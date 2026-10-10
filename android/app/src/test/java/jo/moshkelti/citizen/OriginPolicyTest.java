package jo.moshkelti.citizen;

import org.junit.Test;
import static org.junit.Assert.*;

public class OriginPolicyTest {
    private final OriginPolicy policy = new OriginPolicy("https://10.35.190.193:8443");

    @Test public void citizenPagesAndSignedEvidenceStayInternal() {
        assertTrue(policy.internal("https://10.35.190.193:8443/reports/create?lang=ar"));
        assertTrue(policy.internal("https://10.35.190.193:8443/attachments/example/preview?signature=abc"));
    }

    @Test public void unsafeOriginsAndAdministrationAreRejected() {
        String[] denied = {"http://10.35.190.193:8443/login", "https://10.35.190.193/login",
            "https://10.35.190.193:8000/admin", "https://attacker.test:8443/", "javascript:alert(1)",
            "file:///etc/passwd", "content://provider/private", "intent://example", "data:text/html,x",
            "https://user@10.35.190.193:8443/", "https://10.35.190.193:8443/admin",
            "https://10.35.190.193:8443/admin/login", "https://10.35.190.193:8443/ADMIN/mfa/setup",
            "https://10.35.190.193:8443/%61dmin/login", "https://10.35.190.193:8443/x/../admin",
            "https://10.35.190.193:8443/%2561dmin", "https://10.35.190.193:8443/x%2fadmin"};
        for (String value : denied) { assertFalse(value, policy.internal(value)); }
    }

    @Test public void mapRequestsAreNarrowlyAllowlisted() {
        assertTrue(policy.tile("https://tile.openstreetmap.org/13/4914/3328.png"));
        assertFalse(policy.tile("https://tile.openstreetmap.org/13/4914/3328.png?report=private"));
        assertFalse(policy.tile("https://tile.openstreetmap.org.evil.test/13/1/1.png"));
        assertFalse(policy.tile("http://tile.openstreetmap.org/13/1/1.png"));
    }

    @Test public void externalBrowserNeverReceivesThePilotHostOrUnsafeSchemes() {
        assertTrue(policy.external("https://www.openstreetmap.org/copyright"));
        assertFalse(policy.external("https://10.35.190.193:8000/admin"));
        assertFalse(policy.external("https://127.0.0.1/admin"));
        assertFalse(policy.external("https://localhost/admin"));
        assertFalse(policy.external("intent://example"));
    }
}
