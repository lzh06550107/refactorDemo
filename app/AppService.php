<?php

declare(strict_types=1);

namespace app;

use app\common\audit\StructuredAuditLogger;
use app\common\contract\AuditLogger;
use app\common\contract\TransactionManager;
use app\common\infrastructure\ThinkPhpTransactionManager;
use app\common\security\SecretValue;
use app\iam\contract\AdminSessionRepository;
use app\iam\contract\AdminTenantAccess;
use app\iam\contract\PermissionAuthorizer;
use app\iam\infrastructure\ThinkPhpAdminSessionRepository;
use app\iam\infrastructure\ThinkPhpAdminTenantAccess;
use app\iam\infrastructure\ThinkPhpPermissionAuthorizer;
use app\iam\security\SessionTokenHasher;
use app\miniapp\infrastructure\OpenPlatformAuthorizerAccountBinding;
use app\openplatform\application\AuthorizationEventService;
use app\openplatform\application\AuthorizationStartService;
use app\openplatform\application\AuthorizerConnectionService;
use app\openplatform\application\AuthorizerMetadataSyncService;
use app\openplatform\application\ComponentAccessTokenService;
use app\openplatform\application\ComponentTicketService;
use app\openplatform\application\OpenPlatformEventService;
use app\openplatform\contract\AuthorizationIntentRepository;
use app\openplatform\contract\AuthorizerAccountBinding;
use app\openplatform\contract\AuthorizerAccountEligibility;
use app\openplatform\contract\AuthorizerAccountFinalizer;
use app\openplatform\contract\AuthorizerAccountStateReader;
use app\openplatform\contract\AuthorizerAuthorizationCredentialRepository;
use app\openplatform\contract\AuthorizerAuthorizationRepository;
use app\openplatform\contract\AuthorizerClient;
use app\openplatform\contract\AuthorizerConnectionStore;
use app\openplatform\contract\AuthorizerMetadataRepository;
use app\openplatform\contract\AuthorizerOwnershipRepository;
use app\openplatform\contract\AuthorizerProvisioningRepository;
use app\openplatform\contract\AuthorizerRefreshLeaseRepository;
use app\openplatform\contract\AuthorizerTenantScopeReader;
use app\openplatform\contract\AuthorizerTokenRepository;
use app\openplatform\contract\ComponentCredentialProvider;
use app\openplatform\contract\ComponentEventInboxRepository;
use app\openplatform\contract\ComponentPlatformRepository;
use app\openplatform\contract\ComponentRefreshLeaseRepository;
use app\openplatform\contract\ComponentTicketRepository;
use app\openplatform\contract\ComponentTokenClient;
use app\openplatform\contract\ComponentTokenRepository;
use app\openplatform\contract\OpenPlatformHttpTransport;
use app\openplatform\contract\OpenPlatformSecretCipher;
use app\openplatform\contract\ProvisioningJobRepository;
use app\openplatform\contract\ProvisioningJobScheduler;
use app\openplatform\infrastructure\ConfiguredComponentCredentialProvider;
use app\openplatform\infrastructure\NativeOpenPlatformHttpTransport;
use app\openplatform\infrastructure\OpenSslOpenPlatformSecretCipher;
use app\openplatform\infrastructure\ThinkPhpAuthorizationIntentRepository;
use app\openplatform\infrastructure\ThinkPhpAuthorizerAccountEligibility;
use app\openplatform\infrastructure\ThinkPhpAuthorizerAccountFinalizer;
use app\openplatform\infrastructure\ThinkPhpAuthorizerAccountStateReader;
use app\openplatform\infrastructure\ThinkPhpAuthorizerAuthorizationRepository;
use app\openplatform\infrastructure\ThinkPhpAuthorizerConnectionStore;
use app\openplatform\infrastructure\ThinkPhpAuthorizerMetadataRepository;
use app\openplatform\infrastructure\ThinkPhpAuthorizerOwnershipRepository;
use app\openplatform\infrastructure\ThinkPhpAuthorizerProvisioningRepository;
use app\openplatform\infrastructure\ThinkPhpAuthorizerRefreshLeaseRepository;
use app\openplatform\infrastructure\ThinkPhpAuthorizerTenantScopeReader;
use app\openplatform\infrastructure\ThinkPhpAuthorizerTokenRepository;
use app\openplatform\infrastructure\ThinkPhpComponentEventInboxRepository;
use app\openplatform\infrastructure\ThinkPhpComponentPlatformRepository;
use app\openplatform\infrastructure\ThinkPhpComponentRefreshLeaseRepository;
use app\openplatform\infrastructure\ThinkPhpComponentTicketRepository;
use app\openplatform\infrastructure\ThinkPhpComponentTokenRepository;
use app\openplatform\infrastructure\ThinkPhpProvisioningJobRepository;
use app\openplatform\infrastructure\ThinkPhpProvisioningJobScheduler;
use app\openplatform\infrastructure\WechatAuthorizerClient;
use app\openplatform\infrastructure\WechatComponentTokenClient;
use app\openplatform\security\WechatComponentCallbackAuthenticator;
use JsonException;
use RuntimeException;
use think\Service;

final class AppService extends Service
{
    public function register(): void
    {
        $this->app->bind([
            AuditLogger::class => StructuredAuditLogger::class,
            AdminSessionRepository::class => ThinkPhpAdminSessionRepository::class,
            AdminTenantAccess::class => ThinkPhpAdminTenantAccess::class,
            PermissionAuthorizer::class => ThinkPhpPermissionAuthorizer::class,
            AuthorizationIntentRepository::class => ThinkPhpAuthorizationIntentRepository::class,
            AuthorizerAccountEligibility::class => ThinkPhpAuthorizerAccountEligibility::class,
            AuthorizerAccountBinding::class => OpenPlatformAuthorizerAccountBinding::class,
            AuthorizerAuthorizationRepository::class => ThinkPhpAuthorizerAuthorizationRepository::class,
            AuthorizerAuthorizationCredentialRepository::class => ThinkPhpAuthorizerAuthorizationRepository::class,
            AuthorizerMetadataRepository::class => ThinkPhpAuthorizerMetadataRepository::class,
            AuthorizerOwnershipRepository::class => ThinkPhpAuthorizerOwnershipRepository::class,
            AuthorizerProvisioningRepository::class => ThinkPhpAuthorizerProvisioningRepository::class,
            ProvisioningJobRepository::class => ThinkPhpProvisioningJobRepository::class,
            ProvisioningJobScheduler::class => ThinkPhpProvisioningJobScheduler::class,
            AuthorizerConnectionStore::class => ThinkPhpAuthorizerConnectionStore::class,
            AuthorizerAccountStateReader::class => ThinkPhpAuthorizerAccountStateReader::class,
            AuthorizerAccountFinalizer::class => ThinkPhpAuthorizerAccountFinalizer::class,
            AuthorizerTenantScopeReader::class => ThinkPhpAuthorizerTenantScopeReader::class,
            AuthorizerTokenRepository::class => ThinkPhpAuthorizerTokenRepository::class,
            AuthorizerRefreshLeaseRepository::class => ThinkPhpAuthorizerRefreshLeaseRepository::class,
            ComponentPlatformRepository::class => ThinkPhpComponentPlatformRepository::class,
            ComponentTicketRepository::class => ThinkPhpComponentTicketRepository::class,
            ComponentTokenRepository::class => ThinkPhpComponentTokenRepository::class,
            ComponentRefreshLeaseRepository::class => ThinkPhpComponentRefreshLeaseRepository::class,
            ComponentEventInboxRepository::class => ThinkPhpComponentEventInboxRepository::class,
            ComponentCredentialProvider::class => ConfiguredComponentCredentialProvider::class,
            ComponentTokenClient::class => WechatComponentTokenClient::class,
            OpenPlatformHttpTransport::class => NativeOpenPlatformHttpTransport::class,
            AuthorizerClient::class => WechatAuthorizerClient::class,
            OpenPlatformSecretCipher::class => OpenSslOpenPlatformSecretCipher::class,
            TransactionManager::class => ThinkPhpTransactionManager::class,
        ]);

        $this->registerSecurityFactories();
        $this->registerProviderFactories();
        $this->registerApplicationFactories();
    }

    public function boot(): void
    {
    }

    private function registerSecurityFactories(): void
    {
        $this->app->bind(SessionTokenHasher::class, function (): SessionTokenHasher {
            return new SessionTokenHasher(new SecretValue(
                $this->requiredConfigString('weplatform.admin_session_pepper', 'admin session pepper'),
            ));
        });

        $this->app->bind(OpenSslOpenPlatformSecretCipher::class, function (): OpenSslOpenPlatformSecretCipher {
            $version = $this->requiredConfigString('openplatform.secret_key_version', 'OpenPlatform secret key version');
            $decodedKey = $this->decode32ByteKey(
                $this->requiredConfigString('openplatform.secret_key_base64', 'OpenPlatform secret key'),
                'OpenPlatform secret key',
            );
            return new OpenSslOpenPlatformSecretCipher([$version => $decodedKey], $version);
        });

        $this->app->bind(ConfiguredComponentCredentialProvider::class, function (): ConfiguredComponentCredentialProvider {
            return new ConfiguredComponentCredentialProvider($this->credentialSecretMap());
        });
    }

    private function registerProviderFactories(): void
    {
        $this->app->bind(WechatAuthorizerClient::class, function (): WechatAuthorizerClient {
            return new WechatAuthorizerClient(
                $this->app->make(OpenPlatformHttpTransport::class),
                $this->positiveIntConfig('openplatform.http_timeout_seconds', 'OpenPlatform HTTP timeout'),
            );
        });

        $this->app->bind(WechatComponentTokenClient::class, function (): WechatComponentTokenClient {
            return new WechatComponentTokenClient(
                $this->app->make(OpenPlatformHttpTransport::class),
                $this->positiveIntConfig('openplatform.http_timeout_seconds', 'OpenPlatform HTTP timeout'),
            );
        });
    }

    private function registerApplicationFactories(): void
    {
        $this->app->bind(AuthorizationStartService::class, function (): AuthorizationStartService {
            return new AuthorizationStartService(
                $this->app->make(ComponentAccessTokenService::class),
                $this->app->make(AuthorizerClient::class),
                $this->app->make(AuthorizationIntentRepository::class),
                $this->app->make(AuthorizerAccountEligibility::class),
                $this->requiredConfigString('openplatform.authorization_callback_uri', 'OpenPlatform authorization callback URI'),
            );
        });

        $this->app->bind(ComponentTicketService::class, function (): ComponentTicketService {
            return new ComponentTicketService(
                $this->app->make(WechatComponentCallbackAuthenticator::class),
                $this->app->make(ComponentTicketRepository::class),
                $this->app->make(AuditLogger::class),
            );
        });

        $this->app->bind(AuthorizationEventService::class, function (): AuthorizationEventService {
            return new AuthorizationEventService(
                $this->app->make(AuthorizationIntentRepository::class),
                $this->app->make(\app\openplatform\application\AuthorizationCompletionService::class),
                $this->app->make(ComponentAccessTokenService::class),
                $this->app->make(AuthorizerClient::class),
                $this->app->make(AuthorizerAuthorizationRepository::class),
                $this->app->make(AuditLogger::class),
                $this->app->make(AuthorizerOwnershipRepository::class),
                $this->app->make(AuthorizerConnectionService::class),
                $this->app->make(AuthorizerMetadataSyncService::class),
            );
        });

        $this->app->bind(OpenPlatformEventService::class, function (): OpenPlatformEventService {
            return new OpenPlatformEventService(
                $this->app->make(WechatComponentCallbackAuthenticator::class),
                $this->app->make(ComponentEventInboxRepository::class),
                $this->app->make(ComponentTicketService::class),
                $this->app->make(AuthorizationEventService::class),
            );
        });
    }

    private function requiredConfigString(string $key, string $name): string
    {
        $value = trim((string) $this->app->config->get($key, ''));
        if ($value === '') {
            throw new RuntimeException($name . ' is not configured.');
        }
        return $value;
    }

    private function positiveIntConfig(string $key, string $name): int
    {
        $value = (int) $this->app->config->get($key, 0);
        if ($value <= 0) {
            throw new RuntimeException($name . ' must be a positive integer.');
        }
        return $value;
    }

    private function decode32ByteKey(string $encoded, string $name): string
    {
        $encoded = trim($encoded);
        if ($encoded === '') {
            throw new RuntimeException($name . ' is not configured.');
        }
        $decodedKey = base64_decode($encoded, true);
        if ($decodedKey === false || strlen($decodedKey) !== 32) {
            throw new RuntimeException($name . ' must be strict base64 encoding of exactly 32 bytes.');
        }
        return $decodedKey;
    }

    /** @return array<string,string> */
    private function credentialSecretMap(): array
    {
        $json = $this->requiredConfigString(
            'openplatform.credential_secrets_json',
            'OpenPlatform credential secret map',
        );
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('OpenPlatform credential secret map is invalid.');
        }
        if (!is_array($decoded) || $decoded === []) {
            throw new RuntimeException('OpenPlatform credential secret map is invalid.');
        }

        $result = [];
        foreach ($decoded as $reference => $secret) {
            if (!is_string($reference) || trim($reference) === '' || !is_string($secret) || $secret === '') {
                throw new RuntimeException('OpenPlatform credential secret map is invalid.');
            }
            $reference = trim($reference);
            if (array_key_exists($reference, $result)) {
                throw new RuntimeException('OpenPlatform credential secret map is invalid.');
            }
            $result[$reference] = $secret;
        }
        return $result;
    }
}
