<?php

namespace App\Enums;

/**
 * Stato di uno step dentro una release avviata.
 *
 * I tre casi descrivono una catena che avanza in un solo verso: uno step nasce
 * bloccato, diventa attivo quando tocca a lui, e resta completato. Non esiste un
 * caso "saltato": il PRD non prevede di scavalcare uno step, e ammetterlo qui
 * renderebbe il percorso ricostruibile solo a posteriori.
 *
 * Invariante che l'esecuzione (US-005) dovra mantenere: al massimo uno step
 * `Active` per release, zero quando la release e conclusa.
 */
enum ReleaseStepStatus: string
{
    case Blocked = 'bloccato';
    case Active = 'attivo';
    case Completed = 'completato';

    /**
     * Etichetta leggibile per l'interfaccia.
     */
    public function label(): string
    {
        return match ($this) {
            self::Blocked => 'Bloccato',
            self::Active => 'Attivo',
            self::Completed => 'Completato',
        };
    }
}
