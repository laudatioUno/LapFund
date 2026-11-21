<?php

declare(strict_types=1);

namespace LapFund\Controller;

use LapFund\Http\HttpException;
use LapFund\Http\Request;
use LapFund\Http\Response;

/**
 * Activation of a booked run by the operator, with the signed link from his mail.
 * The link only opens a confirmation page: mail scanners that open links must not
 * activate anything. No login – the operator has no account in the instance.
 */
final class LicenseController extends Controller
{
    public function confirm(Request $request, array $params): Response
    {
        $booking = $this->booking($request, $params);

        return $this->render('license/activate.html.twig', [
            'booking' => $booking,
            'total' => \LapFund\Service\Licensing::total($booking),
            'sig' => $request->input('sig'),
            'activated' => false,
        ]);
    }

    public function activate(Request $request, array $params): Response
    {
        $booking = $this->booking($request, $params);
        $activated = $this->app->licensing()->activate($booking, 'link');

        return $this->render('license/activate.html.twig', [
            'booking' => $this->app->licensing()->find((int) $booking['id']) ?? $booking,
            'total' => \LapFund\Service\Licensing::total($booking),
            'sig' => $request->input('sig'),
            'activated' => $activated,
        ]);
    }

    /** @return array<string, mixed> */
    private function booking(Request $request, array $params): array
    {
        $licensing = $this->app->licensing();
        if (!$licensing->enabled()) {
            throw new HttpException(404);
        }
        $reference = (string) $params['ref'];
        if (!$licensing->license()->validSignature($reference, $request->input('sig'))) {
            if (!$this->app->rateLimiter()->hit('license_link', $request->ip(), 20, 3600)) {
                throw new HttpException(429);
            }
            throw new HttpException(404);
        }
        $booking = $licensing->findByReference($reference);
        if ($booking === null) {
            throw new HttpException(404);
        }

        return $booking;
    }
}
