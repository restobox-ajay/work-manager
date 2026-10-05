<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\Settings\TaxRateRepository;
use App\Service\Settings\TaxRateSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Settings (ADR-078), laid out like the Maxeme Auto settings page: one page with a tab per setting, each tab also a
 * Settings menu entry. Reference lists stay under Config; Settings holds values the app applies (tax rates first).
 * Admins only.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/settings')]
final class SettingsController extends AbstractWorkController
{
    #[Route('', name: 'app_settings', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('app_settings_tax_rates');
    }

    #[Route('/tax-rates', name: 'app_settings_tax_rates', methods: ['GET', 'POST'])]
    public function taxRates(Request $request, TaxRateRepository $rates, TaxRateSettings $settings): Response
    {
        $errors = [];
        $posted = null;
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'settings_tax_rates');
            $posted = $request->request->all()['rows'] ?? [];
            $errors = $settings->save(is_array($posted) ? $posted : [], $this->viewer());
            if ($errors === []) {
                $this->addFlash('success', 'Tax rates saved.');

                return $this->redirectToRoute('app_settings_tax_rates');
            }
        }

        $saved = [];
        foreach ($rates->findAllOrdered() as $rate) {
            $saved[(int) $rate->getId()] = $rate;
        }
        // A rejected save comes back as typed; otherwise the saved rows.
        $rows = is_array($posted) ? array_values(array_filter($posted, 'is_array')) : array_map(static fn ($rate) => [
            'id'       => $rate->getId(),
            'code'     => $rate->getCode(),
            'name'     => $rate->getName(),
            'rate'     => TaxRateSettings::short($rate->getRate()),
            'isActive' => $rate->isActive(),
        ], array_values($saved));

        return $this->render('settings/tax_rates.html.twig', [
            'rows'   => $rows,
            'saved'  => $saved,
            'errors' => $errors,
        ], new Response(status: $errors === [] ? 200 : 422));
    }
}
