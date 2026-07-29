<?php

namespace Tests\Unit;

use App\Services\WhatsApp\WhatsAppTemplateRenderer;
use PHPUnit\Framework\TestCase;

class WhatsAppTemplateRendererTest extends TestCase
{
    public function test_it_renders_known_variables_and_clears_unknown_variables(): void
    {
        $renderer = new WhatsAppTemplateRenderer();
        self::assertSame(
            'Halo Budi, invoice INV-001 -.',
            $renderer->render('Halo {{ nama }}, invoice {{invoice}} {{tidak_ada}}.', [
                'nama' => 'Budi',
                'invoice' => 'INV-001',
            ]),
        );
    }
}
