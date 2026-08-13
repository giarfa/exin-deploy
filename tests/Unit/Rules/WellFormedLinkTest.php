<?php

namespace Tests\Unit\Rules;

use App\Rules\WellFormedLink;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Il criterio di accettazione chiede che un URL malformato sia rifiutato, e il
 * mockup della chiusura fissa **come**: "Indirizzo non valido: manca lo schema
 * (https://) e contiene uno spazio". Qui si verifica sia il rifiuto sia il fatto
 * che il messaggio nomini i difetti trovati — un "non valido" generico
 * costringerebbe chi compila a indovinare la correzione, e passerebbe questo test
 * solo se fosse scritto male.
 */
class WellFormedLinkTest extends TestCase
{
    /**
     * @return list<array{string}>
     */
    public static function validAddresses(): array
    {
        return [
            ['https://ci.gruppoexcellence.com/pipeline/4471'],
            ['http://intranet.local/report'],
            ['https://ci.example.com:8443/report?run=12#esito'],
            ['https://utente:segreto@ci.example.com/report'],
            ['http://[::1]:8080/report'],
            ['https://xn--80ak6aa92e.com/report'],
        ];
    }

    #[DataProvider('validAddresses')]
    public function test_a_well_formed_address_is_accepted(string $address): void
    {
        $this->assertSame([], $this->errorsFor($address));
    }

    public function test_a_missing_scheme_is_refused_and_named(): void
    {
        $errors = $this->errorsFor('ci.gruppoexcellence.com/pipeline/4471');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString(__('validation.well_formed_link.missing_scheme'), $errors[0]);
    }

    public function test_a_scheme_other_than_http_or_https_is_refused_and_named(): void
    {
        $errors = $this->errorsFor('ftp://ci.gruppoexcellence.com/pipeline');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString(
            __('validation.well_formed_link.unsupported_scheme', ['scheme' => 'ftp']),
            $errors[0]
        );
    }

    public function test_a_space_inside_the_address_is_refused_and_named(): void
    {
        $errors = $this->errorsFor('https://ci.gruppoexcellence.com/report 4471');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString(__('validation.well_formed_link.contains_whitespace'), $errors[0]);
    }

    public function test_a_missing_host_is_refused_and_named(): void
    {
        $errors = $this->errorsFor('https://');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString(__('validation.well_formed_link.missing_host'), $errors[0]);
    }

    public function test_a_malformed_host_is_refused_and_named(): void
    {
        $errors = $this->errorsFor('https://-rotto-.com/report');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString(
            __('validation.well_formed_link.malformed_host', ['host' => '-rotto-.com']),
            $errors[0]
        );
    }

    public function test_more_than_one_defect_is_reported_in_a_single_message(): void
    {
        // E il valore esatto del mockup: chi incolla un indirizzo da una chat
        // perde lo schema **e** si porta dietro uno spazio, e scoprire il secondo
        // difetto solo dopo aver corretto il primo e la stessa informazione data
        // nel momento peggiore.
        $errors = $this->errorsFor('ci.gruppoexcellence/report 4471');

        $this->assertCount(1, $errors);
        $this->assertStringContainsString(__('validation.well_formed_link.missing_scheme'), $errors[0]);
        $this->assertStringContainsString(__('validation.well_formed_link.contains_whitespace'), $errors[0]);
    }

    public function test_a_value_that_is_not_a_string_is_refused(): void
    {
        $this->assertCount(1, $this->errorsFor(['https://ci.example.com']));
    }

    public function test_the_rule_stays_silent_on_an_empty_value(): void
    {
        // L'obbligatorieta la decidono `required` e `nullable` in
        // `ReleaseStepField::closingRules()`: due messaggi sullo stesso campo
        // direbbero due volte la stessa cosa in modi diversi.
        $this->assertSame([], $this->errorsFor(null));
        $this->assertSame([], $this->errorsFor(''));
        $this->assertSame([], $this->errorsFor('   '));
    }

    /**
     * Messaggi prodotti dalla regola sul valore indicato.
     *
     * @return list<string>
     */
    private function errorsFor(mixed $value): array
    {
        return Validator::make(
            ['link' => $value],
            ['link' => [new WellFormedLink]]
        )->errors()->get('link');
    }
}
