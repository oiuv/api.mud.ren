<?php

namespace App\Mail\Transport;

use GuzzleHttp\ClientInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class AliyunTransport extends AbstractTransport
{
    public function __construct(private ClientInterface $client, private array $config)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'directmail';
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();
        if (!$email instanceof Email || $email->getAttachments()) {
            throw new TransportException('DirectMail requires an email without attachments.');
        }
        $region = $this->config['region_id'] ?? 'cn-hangzhou';
        $regions = [
            'cn-hangzhou' => ['https://dm.aliyuncs.com', '2015-11-23'],
            'ap-southeast-1' => ['https://dm.ap-southeast-1.aliyuncs.com', '2017-06-22'],
            'ap-southeast-2' => ['https://dm.ap-southeast-2.aliyuncs.com', '2017-06-22'],
        ];
        if (!isset($regions[$region]) || empty($this->config['key']) || empty($this->config['secret'])) {
            throw new TransportException('DirectMail credentials or region are not configured.');
        }

        $addresses = static fn (array $values) => implode(',', array_map(static fn (Address $address) => $address->getAddress(), $values));
        $parameters = array_filter([
            'AccountName' => $this->config['from_address'] ?? $message->getEnvelope()->getSender()->getAddress(),
            'ReplyToAddress' => 'true',
            'AddressType' => $this->config['address_type'] ?? 1,
            'ToAddress' => $addresses(array_merge($email->getTo(), $email->getCc())),
            'BccAddress' => $addresses($email->getBcc()),
            'FromAlias' => $this->config['from_alias'] ?? ($email->getFrom()[0]->getName() ?? ''),
            'Subject' => $email->getSubject(),
            'HtmlBody' => $email->getHtmlBody(),
            'TextBody' => $email->getTextBody(),
            'ClickTrace' => $this->config['click_trace'] ?? 0,
            'Format' => 'json',
            'Action' => 'SingleSendMail',
            'Version' => $regions[$region][1],
            'AccessKeyId' => $this->config['key'],
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureVersion' => '1.0',
            'SignatureNonce' => bin2hex(random_bytes(16)),
            'RegionId' => $region,
        ], static fn ($value) => $value !== null && $value !== '');

        ksort($parameters);
        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $parameters['Signature'] = base64_encode(hash_hmac('sha1', 'POST&%2F&'.rawurlencode($query), $this->config['secret'].'&', true));

        try {
            $response = $this->client->request('POST', $regions[$region][0], [
                'form_params' => $parameters, 'timeout' => 15, 'connect_timeout' => 5, 'http_errors' => false,
            ]);
        } catch (\GuzzleHttp\Exception\GuzzleException $exception) {
            // Do not include a signed request or message body in exception logs.
            throw new TransportException('DirectMail connection failed.');
        }
        $payload = json_decode((string) $response->getBody(), true);
        if ($response->getStatusCode() !== 200 || !is_array($payload) || empty($payload['RequestId']) || isset($payload['Code'])) {
            throw new TransportException('DirectMail rejected the message (HTTP '.$response->getStatusCode().').');
        }
    }
}
