[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
Add-Type -AssemblyName System.IO.Compression.FileSystem
$projectRoot = Split-Path -Parent $PSScriptRoot
$outputDir = Join-Path $projectRoot 'storage/app/pitch-deck'
$previewDir = Join-Path $outputDir 'previews'
if ((Get-PSDrive C).Free -lt 12GB) { throw 'Keep at least 12 GB free; this builder installs no dependencies.' }
New-Item -ItemType Directory -Force -Path $previewDir | Out-Null
$guide = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'PITCH_GUIDE_AR.md') -Raw -Encoding UTF8
$script:palette = @{green='173F35'; paper='F5F2EA'; white='FFFFFF'; mint='C5DDCB'; orange='CC582D'; ink='203D33'; muted='60756B'; line='D9E1D8'; pale='E9EDE4'}

function Color([string]$hex) {
    return [Convert]::ToInt32($hex.Substring(0,2),16) + 256*[Convert]::ToInt32($hex.Substring(2,2),16) + 65536*[Convert]::ToInt32($hex.Substring(4,2),16)
}
function Box($slide,[double]$x,[double]$y,[double]$w,[double]$h,[string]$fill,[bool]$rounded=$false) {
    $type = if ($rounded) { 5 } else { 1 }
    $shape = $slide.Shapes.AddShape($type,$x,$y,$w,$h)
    $shape.Fill.ForeColor.RGB = Color $fill
    $shape.Line.Visible = 0
    if ($rounded) { $shape.Adjustments.Item(1) = 0.13 }
    return $shape
}
function Text($slide,[double]$x,[double]$y,[double]$w,[double]$h,[string]$value,[double]$size=20,[string]$fill='203D33',[bool]$bold=$false,[int]$align=1,[string]$font='Aptos') {
    $shape = $slide.Shapes.AddTextbox(1,$x,$y,$w,$h)
    $shape.TextFrame.MarginLeft=0; $shape.TextFrame.MarginRight=0
    $shape.TextFrame.MarginTop=0; $shape.TextFrame.MarginBottom=0
    $shape.TextFrame.WordWrap=-1; $shape.TextFrame.AutoSize=0
    $range=$shape.TextFrame.TextRange
    $range.Text=$value; $range.Font.Name=$font; $range.Font.Size=$size
    $range.Font.Color.RGB=Color $fill; $range.Font.Bold=if($bold){-1}else{0}
    $range.ParagraphFormat.Alignment=$align
    $range.ParagraphFormat.SpaceAfter=4
    if ($font -eq 'Tahoma') { $range.ParagraphFormat.TextDirection=2 }
    $shape.TextFrame2.AutoSize=0
    $shape.Width=$w; $shape.Height=$h
    return $shape
}
function Line($slide,[double]$x1,[double]$y1,[double]$x2,[double]$y2,[string]$fill='D9E1D8',[double]$weight=1) {
    $shape=$slide.Shapes.AddLine($x1,$y1,$x2,$y2)
    $shape.Line.ForeColor.RGB=Color $fill; $shape.Line.Weight=$weight
    return $shape
}
function Arrow($slide,[double]$x1,[double]$y1,[double]$x2,[double]$y2,[string]$fill='CC582D') {
    $shape=Line $slide $x1 $y1 $x2 $y2 $fill 2
    $shape.Line.EndArrowheadStyle=3
    return $shape
}
function New-Slide([string]$section,[string]$title,[string]$subtitle='',[bool]$dark=$false,[bool]$appendix=$false) {
    $slide=$script:deck.Slides.Add($script:deck.Slides.Count+1,12)
    $slide.FollowMasterBackground=0
    $slide.Background.Fill.ForeColor.RGB=Color $(if($dark){$palette.green}else{$palette.paper})
    $ink=if($dark){$palette.white}else{$palette.ink}
    $muted=if($dark){$palette.mint}else{$palette.muted}
    $null=Box $slide 42 28 24 4 $palette.orange
    $null=Text $slide 78 18 800 24 $section.ToUpperInvariant() 11 $muted $true
    if($title){$null=Text $slide 42 58 876 82 $title 33 $ink $true}
    if($subtitle){$null=Text $slide 42 148 876 42 $subtitle 17 $muted}
    $null=Line $slide 42 506 918 506 $(if($dark){'47635A'}else{$palette.line})
    $null=Text $slide 42 515 720 17 'MOSHKELTI   /   AI QUEST 2026   /   next.society()' 9 $muted $true
    $number=if($appendix){'Q&A '+($slide.SlideIndex-8)}else{('{0:00} / 08' -f $slide.SlideIndex)}
    $null=Text $slide 814 515 104 17 $number 9 $muted $true 3
    $slide.SlideShowTransition.AdvanceOnTime=0
    $slide.SlideShowTransition.AdvanceOnClick=-1
    if($appendix){$slide.SlideShowTransition.Hidden=-1}
    return $slide
}
function Card($slide,[double]$x,[double]$y,[double]$w,[double]$h,[string]$tag,[string]$title,[string]$body,[bool]$dark=$false) {
    $bg=if($dark){'244F42'}else{$palette.white}
    $ink=if($dark){$palette.white}else{$palette.ink}
    $muted=if($dark){$palette.mint}else{$palette.muted}
    $null=Box $slide $x $y $w $h $bg $true
    $null=Text $slide ($x+20) ($y+18) ($w-40) 22 $tag 11 $(if($dark){$palette.mint}else{$palette.orange}) $true
    if($h -lt 200){
        $null=Text $slide ($x+20) ($y+51) ($w-40) 46 $title 20 $ink $true
        if($body){$null=Text $slide ($x+20) ($y+105) ($w-40) ($h-117) $body 14 $muted}
    }else{
        $null=Text $slide ($x+20) ($y+51) ($w-40) 62 $title 24 $ink $true
        $null=Text $slide ($x+20) ($y+125) ($w-40) ($h-139) $body 16 $muted
    }
}
function Main-Notes($slide,[int]$number,[string]$time) {
    $pattern='(?s)### '+$number+' — .*?(?=\r?\n### |\r?\n## |\z)'
    $match=[regex]::Match($guide,$pattern).Value
    if(!$match){throw ('Missing Arabic notes for slide '+$number)}
    $scriptText='TIMING: '+$time+"`r`nDELIVERY: Arabic. Slides: English. Main pitch ends on slide 8.`r`n`r`n"+$match.Replace('**','')
    $notes=$slide.NotesPage.Shapes.Placeholders.Item(2)
    $notes.TextFrame.TextRange.Text=$scriptText
    $notes.TextFrame.TextRange.Font.Name='Tahoma'
    $notes.TextFrame.TextRange.Font.Size=12
}
function Backup-Notes($slide,[string]$value) {
    $slide.NotesPage.Shapes.Placeholders.Item(2).TextFrame.TextRange.Text='Q&A ONLY - hidden from the main sequence. '+$value
}

$createdApplication=$false
try { $application=[Runtime.InteropServices.Marshal]::GetActiveObject('PowerPoint.Application') }
catch { $application=New-Object -ComObject PowerPoint.Application; $createdApplication=$true }
$script:deck=$null
try {
    $script:deck=$application.Presentations.Add(0)
    $deck.PageSetup.SlideWidth=960; $deck.PageSetup.SlideHeight=540

    $s=New-Slide 'AI Quest 2026 / next.society()' '' '' $true
    $null=Text $s 42 87 488 72 'مشكلتي' 52 $palette.white $true 1 'Tahoma'
    $null=Text $s 42 166 488 37 'MOSHKELTI' 25 $palette.mint $true
    $null=Text $s 42 236 478 124 "A report deserves`na clear next step." 36 $palette.white $true
    $null=Text $s 42 384 460 60 "AI-assisted civic triage.`nHuman-reviewed decisions." 20 $palette.mint
    $null=Box $s 579 91 337 337 '244F42' $true
    $null=Text $s 606 119 281 27 'PUBLIC ROAD ISSUE' 11 $palette.mint $true
    $null=Line $s 630 170 853 170 '47635A' 7
    $null=Line $s 630 212 853 212 '47635A' 7
    $pin=$s.Shapes.AddShape(9,715,162,53,53); $pin.Fill.ForeColor.RGB=Color $palette.orange; $pin.Line.Visible=0
    $null=Text $s 606 261 281 50 'Describe. Review. Track.' 25 $palette.white $true
    $null=Text $s 606 337 281 65 "A civic-reporting pilot for Jordan`nIndependent demo · not an official service" 15 $palette.mint
    Main-Notes $s 1 '0:00-0:30 / 30 seconds'

    $s=New-Slide '01 / Jordan & the people who benefit' "The gap is not another form.`nIt is a review-ready case." 'Problem hypothesis: clearer intake may reduce repeated clarification. Not yet measured.'
    Card $s 42 214 278 225 'CITIZEN' 'I know the problem.' "Arabic-first citizens who do not know department names or what evidence is needed."
    Card $s 341 214 278 225 'REVIEWER' 'I need the details.' "A reviewer needs usable context before checking location, jurisdiction and the next action."
    Card $s 640 214 278 225 'OUR FOCUS' 'Prepare, then review.' "Roads, lighting, drainage, waste and public facilities. Start with a bounded Amman pilot."
    $null=Text $s 42 458 876 35 'Official reporting channels already exist. We propose an AI-assisted preparation layer—not a claim of first-ever reporting.' 13 $palette.muted
    Main-Notes $s 2 '0:30-1:10 / 40 seconds'

    $s=New-Slide '02 / the citizen-to-review journey' 'One case. Four accountable steps.' 'The citizen should not need to guess a department—or trust an AI decision.'
    $steps=@(@{x=42;n='01';t='REPORT';b="Arabic or English`nMap-selected location`nPrivate images"},@{x=267;n='02';t='ASSIST';b="Suggest a category`nAsk for missing details`nValidate the output"},@{x=492;n='03';t='REVIEW';b="Verify jurisdiction`nSelect an authority`nExplain the decision"},@{x=717;n='04';t='TRACK';b="Status & history`nRead-only access`nSeparate appeals"})
    foreach($step in $steps){
        $null=Box $s $step.x 217 201 212 $palette.white $true
        $null=Text $s ($step.x+18) 235 160 41 $step.n 30 $palette.orange $true
        $null=Text $s ($step.x+18) 288 170 30 $step.t 19 $palette.ink $true
        $null=Text $s ($step.x+18) 335 169 83 $step.b 15 $palette.muted
    }
    $null=Arrow $s 247 270 260 270; $null=Arrow $s 472 270 485 270; $null=Arrow $s 697 270 710 270
    $null=Text $s 42 461 876 24 'Private by default. Human-selected routing. No automatic government dispatch.' 16 $palette.green $true
    Main-Notes $s 3 '1:10-1:50 / 40 seconds'

    $s=New-Slide '03 / live demo · synthetic data only' 'From a road issue to a visible next step.' 'One synthetic case: citizen submission → assisted review → citizen follow-up.' $true
    $demo=@(@{y=211;n='1';t='Citizen';b='Arabic form, issue location, consent, tracking code.'},@{y=301;n='2';t='Reviewer';b='Recorded analysis, private evidence, authority selection.'},@{y=391;n='3';t='Follow-up';b='Reasoned decision and status. Delivery is simulated.'})
    foreach($row in $demo){
        $null=Box $s 42 $row.y 45 45 $palette.orange $true
        $null=Text $s 42 ($row.y+5) 45 35 $row.n 25 $palette.white $true 2
        $null=Text $s 104 ($row.y-2) 411 34 $row.t 23 $palette.white $true
        $null=Text $s 104 ($row.y+33) 411 43 $row.b 16 $palette.mint
    }
    $null=Box $s 575 206 343 277 $palette.paper $true
    $null=Text $s 597 225 297 20 'ILLUSTRATIVE CASE · NOT A SCREENSHOT' 10 $palette.orange $true
    $null=Text $s 597 271 297 44 'حفرة في طريق عام' 25 $palette.ink $true 3 'Tahoma'
    $null=Text $s 597 331 297 24 'Amman  /  Roads  /  Private evidence' 14 $palette.muted
    $null=Line $s 597 368 895 368 $palette.line
    $null=Text $s 597 391 297 75 "Processing → Human review`nDecision → Simulated acknowledgement" 16 $palette.ink $true
    Main-Notes $s 4 '1:50-3:30 / 100 seconds including window switches'

    $s=New-Slide '04 / AI where it helps—not where it governs' "Gemini recommends.`nLaravel validates. A human decides." 'Text analysis, not a chatbot, computer vision or a fine-tuned model.'
    Card $s 42 221 278 227 'INPUT · CONSENT REQUIRED' 'Minimized text' "Title, description, category and governorate. Best-effort redaction; no account identity or photos."
    Card $s 341 221 278 227 'OUTPUT · CHECKED' 'Review suggestions' "Classification, severity, confidence and missing-information questions in Arabic + English."
    Card $s 640 221 278 227 'AUTHORITY · HUMAN' 'A safe next step' "Human review validates jurisdiction and decides. Unavailable or invalid AI output goes to review."
    $null=Text $s 42 464 876 24 'No AI approval, banning, SQL execution, URL fetching or government dispatch. Confidence is not calibrated accuracy.' 13 $palette.muted
    Main-Notes $s 5 '3:30-4:20 / 50 seconds'

    $s=New-Slide '05 / implemented architecture & engineering proof' 'One backend. No duplicated report logic.' 'Laravel 13 + PHP 8.3 · MySQL-compatible MariaDB · database queue · Java Android WebView'
    $nodes=@(@{x=42;t='Citizen web + APK';b='Citizen-only HTTPS surface'},@{x=267;t='Laravel';b='Policies · validation · versions'},@{x=492;t='Data + queue';b='App DB + separate registry'},@{x=717;t='Analysis + review';b='Gemini → validated suggestion'})
    foreach($node in $nodes){
        $null=Box $s $node.x 211 201 83 $palette.white $true
        $null=Text $s ($node.x+14) 224 173 27 $node.t 17 $palette.ink $true
        $null=Text $s ($node.x+14) 261 173 25 $node.b 12 $palette.muted
    }
    $null=Arrow $s 247 252 260 252; $null=Arrow $s 472 252 485 252; $null=Arrow $s 697 252 710 252
    $null=Text $s 42 333 278 71 '301' 55 $palette.green $true
    $null=Text $s 42 408 278 47 "passing Laravel tests`n1,833 assertions · 10 Oct 2026" 16 $palette.muted
    $null=Text $s 341 337 278 61 'Signed APK' 32 $palette.green $true
    $null=Text $s 341 408 278 47 "Remote build, lint & signature checks`nPhysical-phone testing still required" 15 $palette.muted
    $null=Text $s 640 337 278 61 'Private evidence' 30 $palette.green $true
    $null=Text $s 640 408 278 47 "MFA admin access · signed previews`nRestricted users retain read access" 15 $palette.muted
    $null=Text $s 42 473 876 19 'Tests prove selected behaviors—not complete security, model accuracy, production scale or legal compliance.' 11 $palette.muted
    Main-Notes $s 6 '4:20-5:05 / 45 seconds'

    $s=New-Slide '06 / feasibility, sustainability & a buyer hypothesis' 'Start narrow. Measure value. Scale responsibly.' 'Proposed institutional service—not current revenue, a signed partner or a field-tested impact claim.'
    Card $s 42 213 278 238 'WHO PAYS? · PROPOSED' 'The operator' "Free citizen use. Proposed operator license/service + setup. No signed buyer or validated procurement path."
    Card $s 341 213 278 238 'WHAT WE TEST · PROPOSED' 'One bounded pilot' "One district, one team, six weeks. Measure review time, completeness, clarification loops and cost per case."
    Card $s 640 213 278 238 'HOW WE SCALE · CONDITIONAL' 'Approved local routing' "Reuse the workflow with verified jurisdictions and integrations. Budget for hosting, SMS, support and reviewers."
    $null=Text $s 42 467 876 23 'No ads or sale of citizen data. No promised repair speed. Unit economics and institutional adoption remain unvalidated.' 13 $palette.muted
    Main-Notes $s 7 '5:05-5:50 / 45 seconds'

    $s=New-Slide '07 / the next step & our ask' '' '' $true
    $null=Text $s 42 79 876 106 "One district. One review team.`nA measurable next step." 38 $palette.white $true
    $null=Text $s 42 219 876 38 'Seeking one pilot partner and an independent reviewer-led evaluation.' 23 $palette.mint
    $roadmap=@(@{x=42;n='NOW';t='Working prototype';b='Citizen flow, review, recorded AI and signed APK. Explicit simulations.'},@{x=341;n='NEXT · PROPOSED';t='Bounded validation';b='Synthetic bilingual evaluation, operator agreement and privacy review.'},@{x=640;n='BEFORE REAL USE';t='Approved integration';b='Identity + SMS + jurisdiction + authority delivery, deployment and device checks.'})
    foreach($r in $roadmap){Card $s $r.x 294 278 182 $r.n $r.t $r.b $true}
    $null=Text $s 42 483 876 17 'From a problem in the neighbourhood to a clear next step. Thank you.' 11 $palette.mint
    Main-Notes $s 8 '5:50-6:30 / 40 seconds; reserve 30 seconds before 7:00 cutoff'

    $s=New-Slide 'Q&A / implementation boundaries' 'What works—and what is simulated.' 'Implemented workflows and explicit pilot boundaries.' $false $true
    Card $s 42 211 425 269 'IMPLEMENTED' 'The software workflow' "National-ID/password flow; local OTP stages; citizen forms; private images; validated triage; human review; appeals; versions; queued outbox; signed APK build."
    Card $s 493 211 425 269 'NOT A LIVE GOVERNMENT SERVICE' 'Explicit pilot limits' "Identity data and SMS are synthetic/local. Authorities and delivery are simulated. No trained model or image inference. Phone, impact and load testing are not proven by CI."
    Backup-Notes $s 'بيّن أن التدفق منفذ، لكن التحقق الحكومي والرسائل والتسليم ليست اتصالات رسمية. القبول ليس إصلاح الطريق. Sources: README.md, docs/ANDROID_PILOT.md, app services and jobs.'

    $s=New-Slide 'Q&A / competitive context' 'Review preparation—not another reporting channel.' 'Amman Is Listening already advertises photos, geolocation, tracking and Arabic/English support.' $false $true
    Card $s 42 212 425 233 'OFFICIAL CHANNELS · PUBLISHED FEATURES' 'Reporting already exists' "GAM has an official citizen app and complaint portal. MoDEE refers citizens to Bekhidmetkom. We have not independently benchmarked their AI or reviewer workflows."
    Card $s 493 212 425 233 'OUR DEMONSTRABLE ANGLE' 'Review preparation' "Structured model suggestions, bilingual missing-information questions, human decision gates, private/public separation and auditable state transitions. Not proven globally unique."
    $source=Text $s 42 462 876 28 'Source: official GAM Play listing + GAM complaint portal + MoDEE services. Accessed 10 Oct 2026.' 11 $palette.muted
    $source.ActionSettings.Item(1).Hyperlink.Address='https://play.google.com/store/apps/details?id=com.gam.ammanislistening'
    Backup-Notes $s 'لا تدّع أن خصائص المنافس غائبة أو أن مشكلتي أول نظام من نوعه. المصدر يثبت ما يعلنه تطبيق الأمانة فقط. https://play.google.com/store/apps/details?id=com.gam.ammanislistening ; https://www.amman.jo/ar/eservices/Complaintcallcenter.aspx ; https://www.modee.gov.jo/EN/Pages/Services'

    $s=New-Slide 'Q&A / evaluation plan—not results' 'Prove usefulness before promising impact.' 'Proposed evaluation: 100 synthetic cases; 60 Arabic, 20 English and 20 mixed-language.' $false $true
    Card $s 42 214 278 261 'MODEL QUALITY' 'Reviewer-labelled set' "Two reviewers + adjudication. Measure category/classification precision, recall and macro-F1; track unsafe outputs and needed overrides. No current score claimed."
    Card $s 341 214 278 261 'USER + OPERATOR VALUE' 'Comparable workflows' "Compare review time, completeness and clarification loops on matched case types. Test Arabic task completion and privacy exposure. No promised repair-time reduction."
    Card $s 640 214 278 261 'FAILURE + ABUSE' 'Adversarial checks' "Test prompt injection, malformed output, consent denial, provider outage, unclear jurisdiction and sensitive text. Model confidence is not calibrated probability."
    Backup-Notes $s 'هذه خطة تقييم، وليست تجربة أجريناها أو نتيجة ناجحة. حجم 100 وتوزيع اللغة أهداف مقترحة. اختبار برنامج Laravel لا يثبت دقة النموذج أو أثره على أعمال البلدية.'

    $s=New-Slide 'Q&A / unit economics & sustainability' 'Count the whole operating cost.' 'No price, cost saving or revenue has been validated.' $false $true
    $null=Box $s 42 215 876 94 $palette.green $true
    $null=Text $s 63 237 834 53 'Cost per case = total pilot operating cost / cases handled' 27 $palette.white $true 2
    Card $s 42 334 278 143 'TECHNICAL COST' 'Compute + storage' ''
    Card $s 341 334 278 143 'VARIABLE COST' 'AI calls + SMS' ''
    Card $s 640 334 278 143 'SERVICE COST' 'Review + support' ''
    $null=Text $s 62 434 238 32 'Hosting, backups, monitoring' 14 $palette.muted
    $null=Text $s 361 434 238 32 'Tokens, retries, provider charges' 14 $palette.muted
    $null=Text $s 660 434 238 32 'Onboarding, privacy, staffing' 14 $palette.muted
    Backup-Notes $s 'العميل المؤسسي المقترح يدفع؛ المواطن لا يدفع. لا يوجد سعر مثبت. سقف الاستدعاءات اليومي لا يساوي سقف فاتورة نقدياً. أضف تكاليف الاستضافة والرسائل والتخزين والدعم والمراجعة. احتسب تكلفة كل حالة بما في ذلك الفشل والاستيضاح، لا الاستدعاءات الناجحة فقط.'

    $s=New-Slide 'Q&A / trust, access & remaining work' 'Defense in depth. No absolute guarantees.' 'The pilot is not certified secure, production-ready or legally compliant.' $false $true
    Card $s 42 216 278 260 'CURRENT CONTROLS' 'Server-side gates' "Policies + restricted-account checks; MFA; CSRF; scoped private evidence; idempotency; stale-version rejection; validated AI output; no model tools."
    Card $s 341 216 278 260 'ANDROID BOUNDARY' 'Citizen-only HTTPS' "Separate server mode blocks admins. Narrow local CA trust; invalid TLS refused; no native JS bridge. APK needs the PC. Physical-device checks still required."
    Card $s 640 216 278 260 'BEFORE REAL CITIZENS' 'Operational approval' "Real identity/SMS; provider privacy terms; maintained scanning; encrypted backups and restore test; jurisdiction data; approved delivery; device, load and security review."
    Backup-Notes $s 'الحدود متعددة لكنها ليست ضماناً ضد كل حقن تعليمات أو كل ثغرة. التشفير لبعض حقول قاعدة البيانات لا يعني تشفير كل ملف أو كل نسخة احتياطية. طلبات الخصوصية تسجل للمراجعة، وليست تنفيذاً قانونياً تلقائياً. المصادر: docs/SECURITY.md وREADME.md.'

    $s=New-Slide 'Q&A / evidence, deliverables & references' 'Evidence you can inspect.' 'Rubric and schedule: organizer brief supplied by the team. No invented user research or traction.' $false $true
    $links=@(
        @{y=209;t='Competition brief';b='7 min + 3 min; six criteria; submission before 13:00 on Day 2 (verify organizer updates).';u='https://aiquest2026.site/#/hack'},
        @{y=281;t='Existing official solution';b='GAM app listing describes reporting, photos, location, tracking and Arabic/English.';u='https://play.google.com/store/apps/details?id=com.gam.ammanislistening'},
        @{y=353;t='Main source + run instructions';b='AI_HACKTHON_2026 · README.md · docs/SECURITY.md · docs/ANDROID_PILOT.md';u='https://github.com/Coder-511/AI_HACKTHON_2026'},
        @{y=425;t='Citizen-only Android source';b='Separate private repository. Judges need explicitly authorized repository access.';u='https://github.com/Coder-511/moshkelti-citizen-android-pilot'}
    )
    foreach($item in $links){
        $link=Text $s 42 $item.y 876 27 $item.t 20 $palette.green $true
        $link.ActionSettings.Item(1).Hyperlink.Address=$item.u
        $null=Text $s 42 ($item.y+30) 876 30 $item.b 14 $palette.muted
    }
    Backup-Notes $s 'يمكن الضغط على العناوين لفتح المصادر. الروابط الخاصة تحتاج وصولاً للحكام. المصدر المحلي هو أساس الادعاءات؛ لا تفترض أن أحدث تغييرات سطح أندرويد مرفوعة في مستودع Laravel الرئيسي. قيّم التسليم الفعلي ولا تعتبر مربعات المتصفح إثبات إرسال. مصادر إضافية: https://www.modee.gov.jo/EN/Pages/Services ; https://www.amman.jo/ar/eservices/Complaintcallcenter.aspx. تاريخ التوثيق 10 أكتوبر 2026.'

    $overflow=@()
    foreach($slide in $deck.Slides){
        foreach($shape in $slide.Shapes){
            if($shape.Left -lt -0.5 -or $shape.Top -lt -0.5 -or ($shape.Left+$shape.Width) -gt 960.5 -or ($shape.Top+$shape.Height) -gt 540.5){throw ('Shape outside canvas: slide '+$slide.SlideIndex)}
            if($shape.HasTextFrame -eq -1 -and $shape.TextFrame.HasText -eq -1){
                if($shape.TextFrame2.TextRange.BoundHeight -gt ($shape.Height+2)){
                    $overflow+=('Slide '+$slide.SlideIndex+' (font '+$shape.TextFrame.TextRange.Font.Size+', bounds '+[math]::Round($shape.TextFrame2.TextRange.BoundHeight,1)+' / box '+$shape.Height+'): '+$shape.TextFrame.TextRange.Text)
                }
            }
        }
    }
    if($overflow.Count -gt 0){throw ('Text exceeds fixed boxes: '+($overflow -join ' | '))}
    if($deck.Slides.Count -ne 14){throw 'Expected 8 pitch slides and 6 hidden Q&A slides.'}
    $pptx=Join-Path $outputDir 'Moshkelti_AI_Quest_2026.pptx'
    $pdf=Join-Path $outputDir 'Moshkelti_AI_Quest_2026.pdf'
    $deck.SaveAs($pptx,24)
    foreach($slide in $deck.Slides){$slide.Export((Join-Path $previewDir ('slide-{0:00}.png' -f $slide.SlideIndex)),'PNG',1600,900)}
    # Include the hidden Q&A pages in the reading copy, then restore pitch-only navigation.
    for($i=9;$i -le 14;$i++){$deck.Slides.Item($i).SlideShowTransition.Hidden=0}
    $deck.SaveAs($pdf,32)
    for($i=9;$i -le 14;$i++){$deck.Slides.Item($i).SlideShowTransition.Hidden=-1}
    $deck.SaveAs($pptx,24)
    $deck.Close(); $script:deck=$null

    $sheet=New-Object Drawing.Bitmap(1600,1610)
    $graphics=[Drawing.Graphics]::FromImage($sheet)
    $graphics.Clear([Drawing.ColorTranslator]::FromHtml('#F5F2EA'))
    $labelFont=New-Object Drawing.Font('Arial',13,[Drawing.FontStyle]::Bold)
    for($i=1;$i -le 14;$i++){
        $row=[math]::Floor(($i-1)/3); $col=($i-1)%3
        $image=[Drawing.Image]::FromFile((Join-Path $previewDir ('slide-{0:00}.png' -f $i)))
        $graphics.DrawImage($image,[int](12+$col*530),[int](15+$row*320),506,285)
        $graphics.DrawString(('Slide '+$i),$labelFont,[Drawing.Brushes]::DarkSlateGray,[single](14+$col*530),[single](302+$row*320))
        $image.Dispose()
    }
    $sheet.Save((Join-Path $outputDir 'contact-sheet.png'),[Drawing.Imaging.ImageFormat]::Png)
    $labelFont.Dispose(); $graphics.Dispose(); $sheet.Dispose()

    $archive=[IO.Compression.ZipFile]::OpenRead($pptx)
    try {
        $slideCount=@($archive.Entries | Where-Object FullName -match '^ppt/slides/slide\d+\.xml$').Count
        $notesCount=@($archive.Entries | Where-Object FullName -match '^ppt/notesSlides/notesSlide\d+\.xml$').Count
        if($slideCount -ne 14 -or $notesCount -ne 14){throw 'PowerPoint package slide/notes count failed.'}
        if($archive.Entries | Where-Object FullName -match 'vbaProject|embeddings/'){throw 'Unexpected macro or embedded executable content.'}
        foreach($entry in $archive.Entries | Where-Object FullName -match '\.xml$'){
            $reader=New-Object IO.StreamReader($entry.Open())
            try{$raw=$reader.ReadToEnd(); $null=[xml]$raw}finally{$reader.Dispose()}
            if($raw -match 'AQ\.Ab8|GEMINI_API_KEY=|password.dpapi|-----BEGIN.*PRIVATE KEY'){throw 'Sensitive material detected in deck.'}
        }
    }finally{$archive.Dispose()}
    $bundle=Join-Path $outputDir 'Moshkelti_Presentation_Package.zip'
    Compress-Archive -LiteralPath @($pptx,$pdf,(Join-Path $PSScriptRoot 'PITCH_GUIDE_AR.md'),(Join-Path $outputDir 'contact-sheet.png')) -DestinationPath $bundle -Force
    'Created editable PPTX, PDF, Arabic speaker notes, presentation bundle, and 14 slide previews.'
    Get-Item $pptx,$pdf,$bundle,(Join-Path $outputDir 'contact-sheet.png') | Select-Object FullName,Length
} finally {
    if($script:deck){$script:deck.Close()}
    if($application){
        if($createdApplication -and $application.Presentations.Count -eq 0){$application.Quit()}
        [void][Runtime.InteropServices.Marshal]::ReleaseComObject($application)
    }
}
