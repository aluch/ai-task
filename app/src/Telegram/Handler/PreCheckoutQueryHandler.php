<?php

declare(strict_types=1);

namespace App\Telegram\Handler;

use App\Entity\User;
use App\Service\Subscription\Provider\YooKassa\PaymentValidator;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;

/**
 * Обрабатывает pre_checkout_query от Telegram — это последний шанс
 * отказаться от платежа до фактического списания. На стороне Telegram:
 *  - валидируем payload;
 *  - проверяем что user_id совпадает с from.id;
 *  - что сумма совпадает с указанной в invoice;
 *  - что у юзера ещё нет активной Pro.
 *
 * Реальная валидация — в {@see PaymentValidator}, чтобы можно было
 * прогнать smoke без поднятия Nutgram.
 */
class PreCheckoutQueryHandler
{
    public function __construct(
        private readonly PaymentValidator $validator,
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Nutgram $bot): void
    {
        $query = $bot->preCheckoutQuery();
        if ($query === null) {
            return;
        }

        // Резолвим юзера по telegram_id. Если не нашёлся — payment отбит.
        $em = $this->doctrine->getManager();
        $user = $em->getRepository(User::class)
            ->findOneBy(['telegramId' => (string) $query->from->id]);

        $error = $this->validator->validatePreCheckout(
            invoicePayloadJson: $query->invoice_payload,
            totalAmount: $query->total_amount,
            currency: $query->currency,
            user: $user,
        );

        if ($error !== null) {
            $this->logger->warning('pre_checkout rejected', [
                'telegram_id' => $query->from->id,
                'reason' => $error,
                'total_amount' => $query->total_amount,
                'currency' => $query->currency,
            ]);
            $bot->answerPreCheckoutQuery(ok: false, error_message: $error);

            return;
        }

        $this->logger->info('pre_checkout accepted', [
            'user_id' => $user?->getId()->toRfc4122(),
            'total_amount' => $query->total_amount,
        ]);
        $bot->answerPreCheckoutQuery(ok: true);
    }
}
