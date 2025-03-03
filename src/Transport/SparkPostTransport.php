<?php

namespace Vemcogroup\SparkPostDriver\Transport;

use JsonException;
use GuzzleHttp\ClientInterface;
use Illuminate\Http\JsonResponse;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use function ucfirst;
use function base64_encode;

class SparkPostTransport implements TransportInterface
{
    /**
     * Guzzle client instance.
     *
     * @var ClientInterface
     */
    protected $client;

    /**
     * The SparkPost API key.
     *
     * @var string
     */
    protected $key;

    /**
     * The SparkPost transmission options.
     *
     * @var array
     */
    protected $options = [];

    /**
     * Create a new SparkPost transport instance.
     *
     * @param ClientInterface $client
     * @param  string  $key
     * @param  array  $options
     * @return void
     */
    public function __construct(ClientInterface $client, $key, $options = [])
    {
        $this->key = $key;
        $this->client = $client;
        $this->options = $options;
    }

    public function __toString(): string
    {
        return 'sparkpost';
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getEndpoint(): string
    {
        return ($this->getOptions()['endpoint'] ?? 'https://api.sparkpost.com/api/v1');
    }

    public function send(RawMessage $message, Envelope $envelope = null): ?SentMessage
    {
        $recipients = $this->getRecipients($message);

        $response = $this->client->request('POST', $this->getEndpoint() . '/transmissions', [
            'headers' => [
                'Authorization' => $this->key,
            ],
            'json' => array_merge([
                'recipients' => $recipients,
                'content' => [
                    'from' => $this->getFrom($message),
                    'subject' => $message->getSubject(),
                    'html' => $message->getHtmlBody(),
                    'text' => $message->getTextBody(),
                    'attachments' => $this->getAttachments($message),
                ],
            ], $this->options),
        ]);

        $message->getHeaders()->addTextHeader(
            'X-SparkPost-Transmission-ID', $this->getTransmissionId($response)
        );

        return new SentMessage($message, $envelope);;
    }

    protected function getFrom(RawMessage $message): ?array
    {
        $from = $message->getFrom();
        if (count($from)) {
            return ['name' => $from[0]->getName(), 'email' => $from[0]->getAddress()];
        }

        return null;
    }

    protected function getRecipients(RawMessage $message): array
    {
        $recipients = [];

        foreach (['to', 'cc', 'bcc'] as $type) {
            if ($addresses = $message->{'get' . ucfirst($type)}()) {
                foreach ($addresses as $recipient) {
                    $recipients[] = [
                        'address' => [
                            'name' => $recipient->getName(),
                            'email' => $recipient->getAddress(),
                        ],
                    ];
                }
            }
        }

        return $recipients;
    }

    /**
     * @throws JsonException
     */
    protected function getTransmissionId($response): string
    {
        return object_get(
            json_decode($response->getBody()->getContents(), false, 512, JSON_THROW_ON_ERROR), 'results.id'
        );
    }

    protected function getAttachments(RawMessage $message): array
    {
        $attachments = [];

        foreach ($message->getAttachments() as $attachment) {
            $attachments[] = [
                'name' => $attachment->getPreparedHeaders()->get('content-disposition')->getParameter('filename'),
                'type' => $attachment->getMediaType() . '/' . $attachment->getMediaSubtype(),
                'data' => base64_encode($attachment->getBody()),
            ];
        }

        return $attachments;
    }

    public function validateSingleRecipient($email): JsonResponse
    {
        try {
            $response = $this->client->request('GET', $this->getEndpoint() . '/recipient-validation/single/' . $email, [
                'headers' => [
                    'Authorization' => $this->key,
                ],
            ]);

            return response()->json([
                'code' => $response->getStatusCode(),
                'results' => object_get(
                    json_decode($response->getBody()->getContents()), 'results'
                ),
            ]);
        } catch (\Throwable $th) {
            $message = 'An error occured';
            $errors = json_decode($th->getResponse()->getBody()->getContents(), true)['errors'] ?? [];
            if (isset($errors) && count($errors)) {
                $message = $errors[0]['message'];
            }

            return response()->json([
                'code' => $th->getCode(),
                'message' => $message,
            ]);
        }
    }

    public function deleteSupression($email): JsonResponse
    {
        try {
            $response = $this->client->request('DELETE', $this->getEndpoint() . '/suppression-list/' . $email, [
                'headers' => [
                    'Authorization' => $this->key,
                ],
            ]);

            return response()->json([
                'code' => $response->getStatusCode(),
                'message' => 'Recipient has been removed from supression list',
            ]);
        } catch(\Exception $e) {
            $message = 'An error occured';

            if ($e->getCode() === 403) {
                $message = 'Recipient could not be removed - Compliance';
            }

            if ($e->getCode() === 404) {
                $message = 'Recipient could not be found';
            }

            return response()->json([
                'code' => $e->getCode(),
                'message' => $message,
            ]);
        }
    }
}
