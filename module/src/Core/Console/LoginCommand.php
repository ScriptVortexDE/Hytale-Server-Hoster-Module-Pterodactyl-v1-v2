<?php

namespace HytaleHosting\Core\Console;

use HytaleHosting\Core\Exceptions\HytaleApiException;
use HytaleHosting\Core\Hytale\HytaleClient;
use HytaleHosting\Core\Services\DeviceLoginService;
use Illuminate\Console\Command;

class LoginCommand extends Command
{
    protected $signature = 'hytale-hosting:login {name=default : A name for the provider account}';

    protected $description = 'Authorise a Hytale provider account with the OAuth device code flow.';

    public function handle(HytaleClient $client, DeviceLoginService $login): int
    {
        $name = (string) $this->argument('name');
        if (!preg_match('/^[A-Za-z0-9 ._-]{1,64}$/', $name)) {
            $this->error('The name may only contain letters, numbers, spaces, dots, dashes and underscores.');

            return self::FAILURE;
        }

        try {
            $authorization = $client->startDeviceAuthorization();
        } catch (HytaleApiException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('  Open: <info>' . ($authorization['verification_uri_complete'] ?? $authorization['verification_uri']) . '</info>');
        $this->line('  Code: <info>' . $authorization['user_code'] . '</info>');
        $this->line('  Sign in with the Hytale account that owns your provider entitlement.');
        $this->newLine();

        $interval = $authorization['interval'];
        $deadline = time() + $authorization['expires_in'];

        while (time() < $deadline) {
            sleep($interval);

            try {
                $result = $client->pollDeviceAuthorization($authorization['device_code']);
            } catch (HytaleApiException $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            switch ($result['status']) {
                case 'pending':
                    continue 2;
                case 'slow_down':
                    $interval += 5;
                    continue 2;
                case 'ok':
                    $account = $login->storeAccount($name, $result['tokens']);
                    $this->info("Account \"{$account->name}\" authorised.");
                    $this->line('Profile: ' . ($account->profile_username ?? 'none') . ' (' . ($account->profile_uuid ?? '-') . ')');

                    return self::SUCCESS;
                default:
                    $this->error("The login was {$result['status']}.");

                    return self::FAILURE;
            }
        }

        $this->error('The code expired. Run the command again.');

        return self::FAILURE;
    }
}
