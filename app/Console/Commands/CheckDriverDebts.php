<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\FcmService;
use App\Services\KyaSmsService;
use App\Support\DriverDebt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckDriverDebts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'drivers:check-debts {--dry-run : Parcourir sans envoyer de notifications}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Vérifie les dettes des chauffeurs et envoie des notifications/SMS selon les seuils';

    /**
     * Execute the console command.
     */
    public function handle(FcmService $fcm, KyaSmsService $sms)
    {
        $dryRun = $this->option('dry-run');

        $drivers = User::where('role', 'driver')
            ->leftJoin('wallets', 'users.id', '=', 'wallets.user_id')
            ->select('users.*', 'wallets.balance')
            ->get();

        $this->info("Analyse de " . $drivers->count() . " chauffeurs...");

        foreach ($drivers as $driver) {
            $balance = $driver->balance ?? 0;
            $debt = DriverDebt::amount($balance);
            $level = DriverDebt::level($balance);

            if ($level === 'ok') {
                continue;
            }

            $this->line("Chauffeur #{$driver->id} ({$driver->name}) - Dette: " . number_format($debt) . " XOF - Niveau: {$level}");

            if ($dryRun) {
                continue;
            }

            // 1. Notification Push (toujours envoyée si niveau > ok)
            $this->sendPushNotification($fcm, $driver, $debt, $level);

            // 2. SMS (seulement pour Alerte et Blocage)
            if ($level === 'alert' || $level === 'blocked') {
                $this->sendSms($sms, $driver, $debt, $level);
            }
        }

        $this->info("Terminé.");
    }

    protected function sendPushNotification(FcmService $fcm, User $driver, int $debt, string $level)
    {
        $title = "Alerte Dette Kêkênon";
        $body = match ($level) {
            'notify' => "Votre dette s'élève à " . number_format($debt) . " XOF. Pensez à recharger votre compte pour éviter une suspension.",
            'alert' => "ALERTE : Votre dette est de " . number_format($debt) . " XOF. Rechargez immédiatement votre compte pour continuer à recevoir des courses.",
            'blocked' => "COMPTE BLOQUÉ : Votre dette de " . number_format($debt) . " XOF a atteint le seuil critique. Rechargez pour débloquer votre compte.",
            default => null,
        };

        if ($body) {
            $fcm->sendToUser($driver, $title, $body, [
                'type' => 'wallet_debt_alert',
                'debt_level' => $level,
                'debt_amount' => (string) $debt,
            ]);
            $this->info("  -> Push envoyé à #{$driver->id}");
        }
    }

    protected function sendSms(KyaSmsService $sms, User $driver, int $debt, string $level)
    {
        if (empty($driver->phone)) {
            $this->warn("  -> SMS impossible : pas de numéro pour #{$driver->id}");
            return;
        }

        $message = match ($level) {
            'alert' => "Kêkênon: Alerte dette " . number_format($debt) . " F. Veuillez recharger votre compte pour rester actif.",
            'blocked' => "Kêkênon: Compte BLOQUÉ (dette " . number_format($debt) . " F). Rechargez votre compte pour reprendre votre service.",
            default => null,
        };

        if ($message) {
            try {
                $sms->sendSmsMessage($driver->phone, $message);
                $this->info("  -> SMS envoyé à {$driver->phone}");
            } catch (\Exception $e) {
                $this->error("  -> Erreur SMS pour #{$driver->id}: " . $e->getMessage());
            }
        }
    }
}
