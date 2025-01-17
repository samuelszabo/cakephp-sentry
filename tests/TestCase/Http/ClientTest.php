<?php
declare(strict_types=1);

namespace CakeSentry\Test\TestCase\Http;

use Cake\Core\Configure;
use Cake\Error\PhpError;
use Cake\Event\Event;
use Cake\Event\EventManager;
use Cake\TestSuite\TestCase;
use CakeSentry\Http\SentryClient;
use Exception;
use RuntimeException;
use Sentry\ClientBuilder;
use Sentry\ClientInterface;
use Sentry\Event as SentryEvent;
use Sentry\EventHint;
use Sentry\EventId;
use Sentry\Options;
use Sentry\State\Hub;

final class ClientTest extends TestCase
{
    /**
     * @inheritDoc
     */
    public function setUp(): void
    {
        parent::setUp();

        Configure::write('Sentry.dsn', 'https://yourtoken@example.com/yourproject/1');
    }

    /**
     * Check constructor sets Hub instance
     */
    public function testSetupClient(): void
    {
        $subject = new SentryClient([]);

        $this->assertInstanceOf(Hub::class, $subject->getHub());
    }

    /**
     * Check the configuration values are merged into the default-config.
     */
    public function testSetUpClientMergeConfig(): void
    {
        $userConfig = [
            'dsn' => false,
            'in_app_exclude' => ['/app/vendor', '/app/tmp',],
            'server_name' => 'test-server',
        ];

        Configure::write('Sentry', $userConfig);
        $subject = new SentryClient([]);

        $this->assertSame([APP], $subject->getConfig('sentry.prefixes'), 'Default value not applied');
        $this->assertSame($userConfig['in_app_exclude'], $subject->getConfig('sentry.in_app_exclude'), 'Default value is not overwritten');
        $this->assertSame(false, $subject->getConfig('sentry.dsn'), 'Set value is not addes');
    }

    /**
     * Check constructor does not throw exception if no DSN is set
     */
    public function testSetupClientNotHasDsn(): void
    {
        Configure::delete('Sentry.dsn');
        $client = new SentryClient([]);
        $this->assertInstanceOf(SentryClient::class, $client);
    }

    /**
     * Check constructor passes options to sentry client
     */
    public function testSetupClientSetOptions(): void
    {
        Configure::write('Sentry.server_name', 'test-server');

        $subject = new SentryClient([]);
        $options = $subject->getHub()->getClient()->getOptions();

        $this->assertSame('test-server', $options->getServerName());
    }

    /**
     * Check constructor fill before_send option
     */
    public function testSetupClientSetSendCallback(): void
    {
        $callback = function (SentryEvent $event, ?EventHint $hint) {
            return 'this is user callback';
        };
        Configure::write('Sentry.before_send', $callback);

        $subject = new SentryClient([]);
        $actual = $subject
            ->getHub()
            ->getClient()
            ->getOptions()
            ->getBeforeSendCallback();

        $this->assertSame(
            $callback(SentryEvent::createEvent(), null),
            $actual(SentryEvent::createEvent(), null)
        );
    }

    /**
     * Check constructor dispatch event Client.afterSetup
     */
    public function testSetupClientDispatchAfterSetup(): void
    {
        $called = false;
        EventManager::instance()->on(
            'CakeSentry.Client.afterSetup',
            function () use (&$called) {
                $called = true;
            }
        );

        new SentryClient([]);

        $this->assertTrue($called);
    }

    /**
     * Test capture exception
     */
    public function testCaptureException(): void
    {
        $subject = new SentryClient([]);
        $sentryClientP = $this->createConfiguredMock(ClientInterface::class, [
            'captureException' => null,
        ]);
        $subject->getHub()->bindClient($sentryClientP);

        $exception = new RuntimeException('something wrong.');
        $subject->captureException($exception);

        $result = $sentryClientP->captureException($exception);
        $this->assertSame(null, $result);
    }

    /**
     * Test capture error
     */
    public function testCaptureError(): void
    {
        $subject = new SentryClient([]);
        $options = new Options();
        $clientBuilder = new ClientBuilder($options);
        $client = $clientBuilder->getClient();
        $subject->getHub()->bindClient($client);

        $error = new PhpError(E_USER_WARNING, 'something wrong.', '/my/app/path/test.php', 123);
        $subject->captureError($error);

        $result = $client->captureMessage($error->getMessage());
        $this->assertInstanceOf(EventId::class, $result);
    }

    /**
     * Test capture exception pass cakephp-log's context as additional data
     */
    public function testCaptureExceptionWithAdditionalData(): void
    {
        $callback = function (SentryEvent $event, ?EventHint $hint) use (&$actualEvent) {
            $actualEvent = $event;
        };

        $userConfig = [
            'dsn' => false,
            'before_send' => $callback,
        ];

        Configure::write('Sentry', $userConfig);
        $subject = new SentryClient([]);

        $extras = ['this is' => 'additional'];
        $exception = new RuntimeException('Some error');
        $subject->captureException($exception, null, $extras);

        $this->assertSame($extras, $actualEvent->getExtra());
    }

    /**
     * Test capture error pass cakephp-log's context as additional data
     */
    public function testCaptureErrorWithAdditionalData(): void
    {
        $callback = function (SentryEvent $event, ?EventHint $hint) use (&$actualEvent) {
            $actualEvent = $event;
        };

        $userConfig = [
            'dsn' => false,
            'before_send' => $callback,
        ];

        Configure::write('Sentry', $userConfig);
        $subject = new SentryClient([]);

        $extras = ['this is' => 'additional'];
        $phpError = new PhpError(E_USER_WARNING, 'Some error', '/my/app/path/test.php', 123);
        $subject->captureError($phpError, null, $extras);

        $this->assertSame($extras, $actualEvent->getExtra());
    }

    /**
     * Check capture dispatch before exception capture
     */
    public function testCaptureDispatchBeforeExceptionCapture(): void
    {
        $subject = new SentryClient([]);
        $sentryClientP = $this->createConfiguredMock(ClientInterface::class, [
            'captureException' => null,
        ]);
        $subject->getHub()->bindClient($sentryClientP);

        $called = false;
        EventManager::instance()->on(
            'CakeSentry.Client.beforeCapture',
            function () use (&$called) {
                $called = true;
            }
        );

        $exception = new RuntimeException('Some error');
        $subject->captureException($exception, null, ['exception' => new Exception()]);

        $this->assertTrue($called);
    }

    /**
     * Check capture dispatch before error capture
     */
    public function testCaptureDispatchBeforeErrorCapture(): void
    {
        $subject = $this->getClient();

        $called = false;
        EventManager::instance()->on(
            'CakeSentry.Client.beforeCapture',
            function () use (&$called) {
                $called = true;
            }
        );

        $phpError = new PhpError(E_USER_WARNING, 'Some error', '/my/app/path/test.php', 123);
        $subject->captureError($phpError, null, ['exception' => new Exception()]);

        $this->assertTrue($called);
    }

    /**
     * Check capture dispatch after exception capture and receives lastEventId
     */
    public function testCaptureDispatchAfterExceptionCapture(): void
    {
        $subject = $this->getClient();

        $called = false;
        EventManager::instance()->on(
            'CakeSentry.Client.afterCapture',
            function (Event $event) use (&$called, &$actualLastEventId) {
                $called = true;
                $actualLastEventId = $event->getData('lastEventId');
            }
        );

        $phpError = new RuntimeException('Some error');
        $subject->captureException($phpError, null, ['exception' => new Exception()]);

        $this->assertTrue($called);
        $this->assertInstanceOf(EventId::class, $actualLastEventId);
    }

    /**
     * Check capture dispatch after error capture and receives lastEventId
     */
    public function testCaptureDispatchAfterErrorCapture(): void
    {
        $subject = $this->getClient();

        $called = false;
        EventManager::instance()->on(
            'CakeSentry.Client.afterCapture',
            function (Event $event) use (&$called, &$actualLastEventId) {
                $called = true;
                $actualLastEventId = $event->getData('lastEventId');
            }
        );

        $phpError = new PhpError(E_USER_WARNING, 'Some error', '/my/app/path/test.php', 123);
        $subject->captureError($phpError, null, ['exception' => new Exception()]);

        $this->assertTrue($called);
        $this->assertInstanceOf(EventId::class, $actualLastEventId);
    }

    private function getClient(): SentryClient
    {
        $subject = new SentryClient([]);
        $options = new Options();
        $clientBuilder = new ClientBuilder($options);
        $client = $clientBuilder->getClient();
        $subject->getHub()->bindClient($client);

        return $subject;
    }
}
