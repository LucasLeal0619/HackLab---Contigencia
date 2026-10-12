<?php

namespace App\Domain\Integrations\Enums;

/**
 * Categoria interna de uma entrada externa. Classifica a inscrição; não concede conta, perfil nem vínculo.
 */
enum TicketCategory: string
{
    case Participant = 'PARTICIPANT';
    case Organization = 'ORGANIZATION';
    case CompanyGuest = 'COMPANY_GUEST';
    case Juror = 'JUROR';
    case Public = 'PUBLIC';
}
