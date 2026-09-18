<?php

namespace Tests\Unit;

use App\Mail\Transport\AliyunTransport;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Email;

class AliyunTransportTest extends TestCase
{
    public function testSendsSignedHtmlAndTextWithoutExposingBccInToAddress(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new Response(200, [], '{"RequestId":"test-id"}')]));
        $handler->push(Middleware::history($history));
        $transport = new AliyunTransport(new Client(['handler' => $handler]), [
            'key' => 'test-key', 'secret' => 'test-secret', 'region_id' => 'cn-hangzhou',
        ]);
        $email = (new Email())->from('forum@example.test')->to('member@example.test')->bcc('private@example.test')
            ->subject('激活账号')->html('<p>点击激活</p>')->text('点击激活');
        $this->assertNotNull($transport->send($email));
        $request = $history[0]['request'];
        parse_str((string) $request->getBody(), $payload);
        $this->assertSame('https://dm.aliyuncs.com', (string) $request->getUri());
        $this->assertSame('SingleSendMail', $payload['Action']);
        $this->assertSame('member@example.test', $payload['ToAddress']);
        $this->assertSame('private@example.test', $payload['BccAddress']);
        $this->assertSame('<p>点击激活</p>', $payload['HtmlBody']);
        $this->assertSame('点击激活', $payload['TextBody']);
        $this->assertSame('激活账号', $payload['Subject']);
        $signature = $payload['Signature'];
        unset($payload['Signature']);
        ksort($payload);
        $this->assertSame(base64_encode(hash_hmac('sha1', 'POST&%2F&'.rawurlencode(
            http_build_query($payload, '', '&', PHP_QUERY_RFC3986)
        ), 'test-secret&', true)), $signature);
        $this->assertStringNotContainsString('test-secret', (string) $request->getBody());
    }

    public function testApiFailuresDoNotLookLikeSuccessfulDelivery(): void
    {
        $transport = new AliyunTransport(new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(400, [], '{"Code":"InvalidReceiverName.Malformed","RequestId":"test-id"}'),
        ]))]), ['key' => 'test-key', 'secret' => 'test-secret']);
        $this->expectException(TransportException::class);
        $transport->send((new Email())->from('forum@example.test')->to('member@example.test')->subject('Test')->text('Test'));
    }
}
