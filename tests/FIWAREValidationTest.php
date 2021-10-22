<?php

declare(strict_types=1);
include_once __DIR__ . '/stubs/Validator.php';
class FIWAREValidationTest extends TestCaseSymconValidation
{
    public function testValidateFIWARE(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }
    public function testValidateFIWAREModule(): void
    {
        $this->validateModule(__DIR__ . '/../FIWARE');
    }
}
