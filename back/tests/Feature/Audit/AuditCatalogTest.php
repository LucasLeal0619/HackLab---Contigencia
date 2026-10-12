<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\AuditAction;
use App\Domain\Audit\AuditActionCatalog;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Inventário: toda constante de AuditAction tem módulo + rótulo no catálogo, é usada por alguma operação,
 * e cada chamada de AuditLogger::record grava o mesmo módulo do catálogo. Uma ação nova sem catálogo falha aqui.
 */
class AuditCatalogTest extends TestCase
{
    /** Sufixos montados pelo DemandService ("{TASK|OCCURRENCE}_{sufixo}"). */
    private const DEMAND_SUFFIXES = ['UPDATED', 'STATUS_CHANGED', 'FORWARDED'];

    public function test_every_action_constant_has_a_catalog_entry_and_vice_versa(): void
    {
        $declared = AuditActionCatalog::declaredActions();

        $this->assertNotEmpty($declared);
        $this->assertEqualsCanonicalizing($declared, array_keys(AuditActionCatalog::ACTIONS), 'AuditAction e AuditActionCatalog divergem.');

        foreach (AuditActionCatalog::ACTIONS as $code => [$module, $label]) {
            $this->assertArrayHasKey($module, AuditActionCatalog::MODULES, "{$code}: módulo {$module} sem rótulo.");
            $this->assertNotSame('', trim($label), "{$code}: rótulo vazio.");
            $this->assertNotSame($code, $label, "{$code}: rótulo igual ao código.");
        }
    }

    public function test_public_vote_cast_does_not_exist(): void
    {
        $this->assertNotContains('PUBLIC_VOTE_CAST', AuditActionCatalog::declaredActions());
        $this->assertFalse(defined(AuditAction::class.'::PUBLIC_VOTE_CAST'));
    }

    public function test_every_record_call_uses_the_catalog_module_and_every_action_is_used(): void
    {
        $used = [];
        $calls = 0;

        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            $source = $file->getContents();
            $offset = 0;

            while (($position = strpos($source, '->record(', $offset)) !== false) {
                $offset = $position + 9;
                [$action, $module] = $this->firstTwoArguments(substr($source, $offset));

                if (! str_contains($action, 'AuditAction::')) {
                    continue; // DemandService::record(sufixo, ...) e o próprio helper genérico
                }

                $calls++;
                preg_match_all('/AuditAction::([A-Z][A-Z0-9_]*)/', $action, $matches);
                $this->assertMatchesRegularExpression("/^'[a-z_]+'$/", $module, "{$file->getFilename()}: módulo não literal.");

                foreach ($matches[1] as $constant) {
                    $code = constant(AuditAction::class.'::'.$constant);
                    $used[] = $code;
                    $this->assertSame(AuditActionCatalog::moduleOf($code), trim($module, "'"), "{$file->getFilename()}: {$code} gravado com módulo {$module}.");
                }
            }
        }

        foreach (['TASK', 'OCCURRENCE'] as $prefix) {
            foreach (self::DEMAND_SUFFIXES as $suffix) {
                $used[] = "{$prefix}_{$suffix}";
            }
        }

        $this->assertGreaterThan(60, $calls);
        $this->assertSame([], array_values(array_diff(AuditActionCatalog::declaredActions(), $used)), 'Constantes sem nenhuma operação que as grave.');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function firstTwoArguments(string $source): array
    {
        $arguments = [''];
        $depth = 0;
        $quote = null;

        for ($i = 0, $length = strlen($source); $i < $length && count($arguments) <= 2; $i++) {
            $char = $source[$i];

            if ($quote !== null) {
                $arguments[count($arguments) - 1] .= $char;
                $quote = $char === $quote && $source[$i - 1] !== '\\' ? null : $quote;

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif (in_array($char, ['(', '['], true)) {
                $depth++;
            } elseif (in_array($char, [')', ']'], true)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $arguments[] = '';

                continue;
            }

            $arguments[count($arguments) - 1] .= $char;
        }

        return [trim($arguments[0]), trim($arguments[1] ?? '')];
    }
}
