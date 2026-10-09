<?php

namespace App\Contracts;

interface IssueAnalyzer
{
    public function analyze(array $context): array;
}
