<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Core\Contracts\RowFreePolicy;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** ⛔ "সারি দেখে না" বলা নীতি সত্যিই দেখে না — পদ্ধতির ভিতরে সারির চলকটা একবারও নেই ([[RowFreePolicy]]) */
final class ARowFreePolicyNeverLooksAtItsRowTest extends TestCase
{
    public function test_no_declared_policy_touches_its_row(): void
    {
        $looked = 0;

        foreach (File::allFiles(app_path()) as $file) {
            $source = (string) file_get_contents($file->getPathname());

            if ($file->getExtension() !== 'php' || preg_match('/class\s+\w+[^{]*implements[^{]*RowFreePolicy/', $source) !== 1) {
                continue;
            }

            preg_match('/^namespace ([^;]+);/m', $source, $ns);
            $class = new \ReflectionClass($ns[1].'\\'.$file->getBasename('.php'));
            $this->assertFalse($class->hasMethod('before'), "{$class->getName()}::before সারি দেখতে পারে");

            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $body = implode('', array_slice(file((string) $method->getFileName()), $method->getStartLine(), $method->getEndLine() - $method->getStartLine()));

                foreach (array_slice($method->getParameters(), 1) as $row) {
                    $looked++;
                    $this->assertDoesNotMatchRegularExpression('/\$'.$row->getName().'\b|func_get_args|compact\(/', $body,
                        "⛔ {$class->getName()}::{$method->getName()} সারি দেখে — RowFreePolicy বলা মিথ্যা।");
                }
            }
        }

        $this->assertGreaterThan(0, $looked, 'খোঁজাটাই ভেঙেছে।');
    }
}
