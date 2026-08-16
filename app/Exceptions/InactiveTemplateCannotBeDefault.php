<?php

namespace App\Exceptions;

use App\Models\WorkflowTemplate;
use RuntimeException;

/**
 * Un template disattivato non puo diventare il predefinito.
 *
 * Il rifiuto e esplicito e non silenzioso: proporre ai nuovi progetti un template
 * che non e utilizzabile per avviare una release produrrebbe progetti nati gia
 * bloccati, e il motivo emergerebbe solo molto piu tardi.
 */
class InactiveTemplateCannotBeDefault extends RuntimeException
{
    public static function for(WorkflowTemplate $template): self
    {
        return new self(
            "Il template [{$template->name}] e disattivato e non puo essere impostato come predefinito."
        );
    }
}
