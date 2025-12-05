<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use App\Traits\HasGeneralSettings;

class StripeService
{
    use HasGeneralSettings;

    private string $apiKey;
    private string $webhookSecret;

    public function __construct()
    {
        // 从中央数据库读取Stripe配置，确保所有租户使用统一的Stripe设置
        $this->apiKey = (string) $this->getSetting('stripe_secret_key', null, true, true);
        $this->webhookSecret = (string) $this->getSetting('stripe_webhook_secret', null, true, true);

        if (empty($this->apiKey)) {
            throw new \RuntimeException('Stripe API key is not configured. Please configure STRIPE settings in General Settings.');
        }

        Stripe::setApiKey($this->apiKey);
    }

    /**
     * Create a Stripe Checkout Session for subscription payment
     *
     * @param array $data
     * @return array
     */
    public function createCheckoutSession(array $data): array
    {
        try {
            $sessionData = [
                'payment_method_types' => ['card'],
                'mode' => 'payment',
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($data['currency'] ?? 'usd'),
                        'product_data' => [
                            'name' => $data['plan_name'] ?? 'Subscription Plan',
                            'description' => $data['description'] ?? null,
                        ],
                        'unit_amount' => (int) ($data['amount_cents'] ?? 0), // Amount in cents
                    ],
                    'quantity' => 1,
                ]],
                'success_url' => $data['success_url'],
                'cancel_url' => $data['cancel_url'],
                'metadata' => $data['metadata'] ?? [],
            ];

            // Add customer email if provided
            if (!empty($data['customer_email'])) {
                $sessionData['customer_email'] = $data['customer_email'];
            }

            $session = Session::create($sessionData);

            return [
                'id' => $session->id,
                'url' => $session->url,
                'payment_status' => $session->payment_status,
            ];
        } catch (\Throwable $e) {
            Log::error('Failed to create Stripe Checkout Session', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
            throw new \RuntimeException('Failed to create payment session: ' . $e->getMessage());
        }
    }

    /**
     * Retrieve a Stripe Checkout Session
     *
     * @param string $sessionId
     * @return array|null
     */
    public function retrieveSession(string $sessionId): ?array
    {
        try {
            $session = Session::retrieve($sessionId);

            return [
                'id' => $session->id,
                'payment_status' => $session->payment_status,
                'payment_intent' => $session->payment_intent,
                'amount_total' => $session->amount_total,
                'currency' => $session->currency,
                'customer_email' => $session->customer_details->email ?? null,
                'metadata' => $session->metadata->toArray(),
            ];
        } catch (\Throwable $e) {
            Log::error('Failed to retrieve Stripe Checkout Session', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Verify Stripe webhook signature and construct event
     *
     * @param string $payload
     * @param string $signature
     * @return \Stripe\Event
     * @throws SignatureVerificationException
     */
    public function constructWebhookEvent(string $payload, string $signature): \Stripe\Event
    {
        if (empty($this->webhookSecret)) {
            throw new \RuntimeException('Stripe webhook secret is not configured.');
        }

        try {
            return Webhook::constructEvent(
                $payload,
                $signature,
                $this->webhookSecret
            );
        } catch (SignatureVerificationException $e) {
            Log::error('Stripe webhook signature verification failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle checkout session completed event
     *
     * @param \Stripe\Event $event
     * @return array
     */
    public function handleCheckoutSessionCompleted(\Stripe\Event $event): array
    {
        $session = $event->data->object;

        return [
            'session_id' => $session->id,
            'payment_intent' => $session->payment_intent,
            'payment_status' => $session->payment_status,
            'amount_total' => $session->amount_total,
            'currency' => $session->currency,
            'customer_email' => $session->customer_details->email ?? null,
            'metadata' => $session->metadata->toArray(),
        ];
    }

    /**
     * Get payment intent details
     *
     * @param string $paymentIntentId
     * @return array|null
     */
    public function retrievePaymentIntent(string $paymentIntentId): ?array
    {
        try {
            $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId);

            return [
                'id' => $paymentIntent->id,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
                'status' => $paymentIntent->status,
                'description' => $paymentIntent->description,
                'metadata' => $paymentIntent->metadata->toArray(),
            ];
        } catch (\Throwable $e) {
            Log::error('Failed to retrieve Stripe PaymentIntent', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Create a refund for a payment
     *
     * @param string $paymentIntentId
     * @param int|null $amount Amount in cents, null for full refund
     * @return array
     */
    public function createRefund(string $paymentIntentId, ?int $amount = null): array
    {
        try {
            $refundData = ['payment_intent' => $paymentIntentId];

            if ($amount !== null) {
                $refundData['amount'] = $amount;
            }

            $refund = \Stripe\Refund::create($refundData);

            return [
                'id' => $refund->id,
                'amount' => $refund->amount,
                'currency' => $refund->currency,
                'status' => $refund->status,
            ];
        } catch (\Throwable $e) {
            Log::error('Failed to create Stripe refund', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to create refund: ' . $e->getMessage());
        }
    }
}
