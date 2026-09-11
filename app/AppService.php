<?php

declare(strict_types=1);

namespace app;

use app\common\audit\StructuredAuditLogger;
use app\common\contract\AuditLogger;
use app\common\contract\TransactionManager;
use app\common\infrastructure\ThinkPhpTransactionManager;
use app\common\security\SecretValue;
use modules\iam\contract\AdminSessionRepository;
use modules\iam\contract\AdminTenantAccess;
use modules\iam\contract\PermissionAuthorizer;
use modules\iam\infrastructure\ThinkPhpAdminSessionRepository;
use modules\iam\infrastructure\ThinkPhpAdminTenantAccess;
use modules\iam\infrastructure\ThinkPhpPermissionAuthorizer;
use modules\iam\security\SessionTokenHasher;
use modules\miniapp\infrastructure\OpenPlatformAuthorizerAccountBinding;
use modules\openplatform\application\AuthorizationCompletionService;
use modules\openplatform\application\AuthorizationEventService;
use modules\openplatform\application\AuthorizationStartService;
use modules\openplatform\application\AuthorizerConnectionService;
use modules\openplatform\application\AuthorizerMetadataSyncService;
use modules\openplatform\application\AuthorizerOwnershipResolver;
use modules\openplatform\application\AuthorizerProvisioningQuotaService;
use modules\openplatform\application\AuthorizerProvisioningWorker;
use modules\openplatform\application\ComponentAccessTokenService;
use modules\openplatform\application\ComponentTicketService;
use modules\openplatform\application\OpenPlatformAudit;
use modules\openplatform\application\OpenPlatformEventService;
use modules\openplatform\contract\AuthorizationIntentRepository;
use modules\openplatform\contract\AuthorizerAccountBinding;
use modules\openplatform\contract\AuthorizerAccountEligibility;
use modules\openplatform\contract\AuthorizerAccountFinalizer;
use modules\openplatform\contract\AuthorizerAccountStateReader;
use modules\openplatform\contract\AuthorizerAuthorizationCredentialRepository;
use modules\openplatform\contract\AuthorizerAuthorizationRepository;
use modules\openplatform\contract\AuthorizerClient;
use modules\openplatform\contract\AuthorizerConnectionStore;
use modules\openplatform\contract\AuthorizerMetadataRepository;
use modules\openplatform\contract\AuthorizerOwnershipRepository;
use modules\openplatform\contract\AuthorizerProvisioningRepository;
use modules\openplatform\contract\AuthorizerRefreshLeaseRepository;
use modules\openplatform\contract\AuthorizerTenantScopeReader;
use modules\openplatform\contract\AuthorizerTokenRepository;
use modules\openplatform\contract\ComponentCredentialProvider;
use modules\openplatform\contract\ComponentEventInboxRepository;
use modules\openplatform\contract\ComponentPlatformRepository;
use modules\openplatform\contract\ComponentRefreshLeaseRepository;
use modules\openplatform\contract\ComponentTicketRepository;
use modules\openplatform\contract\ComponentTokenClient;
use modules\openplatform\contract\ComponentTokenRepository;
use modules\openplatform\contract\OpenPlatformHttpTransport;
use modules\openplatform\contract\OpenPlatformSecretCipher;
use modules\openplatform\contract\ProvisioningJobRepository;
use modules\openplatform\contract\ProvisioningJobScheduler;
use modules\openplatform\infrastructure\AuditedAuthorizerAccountFinalizer;
use modules\openplatform\infrastructure\AuditedAuthorizerConnectionStore;
use modules\openplatform\infrastructure\AuditedAuthorizerMetadataRepository;
use modules\openplatform\infrastructure\AuditedAuthorizerProvisioningRepository;
use modules\openplatform\infrastructure\ConfiguredComponentCredentialProvider;
use modules\openplatform\infrastructure\NativeOpenPlatformHttpTransport;
use modules\openplatform\infrastructure\OpenSslOpenPlatformSecretCipher;
use modules\openplatform\infrastructure\ThinkPhpAuthorizationIntentRepository;
use modules\openplatform\infrastructure\ThinkPhpAuthorizerAccountEligibility;
use modules\openplatform\infrastructure\ThinkPhpAuthorizerAccountFinalizer;
use modules\openplatform\infrastructure\ThinkPhpAuthorizerAccountStateReader;
use modules\openplatform\infrastructure\ThinkPhpAuthorizerAuthorizationRepository;
use modules\openplatform\infrastructure\ThinkPhpAuthorizerConnectionStore;
use modules\openplatform\infrastructure\ThinkPhpAuthorizerMetadataRepository;
use modules\openplatform\infrastructure\ThinkPhpAuthorizerOwnershipRepository;
use modules\openplatform\infrastructure\ThinkPhpAuthorizerProvisioningRepository;
use modules\openplatform\infrastructure\ThinkPhpAuthorizerRefreshLeaseRepository;
use modules\openplatform\infrastructure\ThinkPhpAuthorizerTenantScopeReader;
use modules\openplatform\infrastructure\ThinkPhpAuthorizerTokenRepository;
use modules\openplatform\infrastructure\ThinkPhpComponentEventInboxRepository;
use modules\openplatform\infrastructure\ThinkPhpComponentPlatformRepository;
use modules\openplatform\infrastructure\ThinkPhpComponentRefreshLeaseRepository;
use modules\openplatform\infrastructure\ThinkPhpComponentTicketRepository;
use modules\openplatform\infrastructure\ThinkPhpComponentTokenRepository;
use modules\openplatform\infrastructure\ThinkPhpProvisioningJobRepository;
use modules\openplatform\infrastructure\ThinkPhpProvisioningJobScheduler;
use modules\openplatform\infrastructure\WechatAuthorizerClient;
use modules\openplatform\infrastructure\WechatComponentTokenClient;
use modules\openplatform\security\WechatComponentCallbackAuthenticator;
use modules\quota\application\QuotaService;
use modules\quota\contract\QuotaLedgerRepository;
use modules\quota\infrastructure\ThinkPhpQuotaLedgerRepository;
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
            AuthorizerMetadataRepository::class => AuditedAuthorizerMetadataRepository::class,
            AuthorizerOwnershipRepository::class => ThinkPhpAuthorizerOwnershipRepository::class,
            AuthorizerProvisioningRepository::class => AuditedAuthorizerProvisioningRepository::class,
            ProvisioningJobRepository::class => ThinkPhpProvisioningJobRepository::class,
            ProvisioningJobScheduler::class => ThinkPhpProvisioningJobScheduler::class,
            AuthorizerConnectionStore::class => AuditedAuthorizerConnectionStore::class,
            AuthorizerAccountStateReader::class => ThinkPhpAuthorizerAccountStateReader::class,
            AuthorizerAccountFinalizer::class => AuditedAuthorizerAccountFinalizer::class,
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
            QuotaLedgerRepository::class => ThinkPhpQuotaLedgerRepository::class,
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
        $this->app->bind(OpenPlatformAudit::class, function (): OpenPlatformAudit {
            return new OpenPlatformAudit($this->app->make(AuditLogger::class));
        });

        $this->app->bind(AuditedAuthorizerProvisioningRepository::class, function (): AuditedAuthorizerProvisioningRepository {
            return new AuditedAuthorizerProvisioningRepository(
                new ThinkPhpAuthorizerProvisioningRepository(),
                $this->app->make(OpenPlatformAudit::class),
            );
        });

        $this->app->bind(AuditedAuthorizerMetadataRepository::class, function (): AuditedAuthorizerMetadataRepository {
            return new AuditedAuthorizerMetadataRepository(
                new ThinkPhpAuthorizerMetadataRepository(),
                $this->app->make(OpenPlatformAudit::class),
            );
        });

        $this->app->bind(AuditedAuthorizerConnectionStore::class, function (): AuditedAuthorizerConnectionStore {
            return new AuditedAuthorizerConnectionStore(
                new ThinkPhpAuthorizerConnectionStore(),
                $this->app->make(OpenPlatformAudit::class),
            );
        });

        $this->app->bind(AuditedAuthorizerAccountFinalizer::class, function (): AuditedAuthorizerAccountFinalizer {
            return new AuditedAuthorizerAccountFinalizer(
                new ThinkPhpAuthorizerAccountFinalizer(),
                $this->app->make(OpenPlatformAudit::class),
            );
        });

        $this->app->bind(QuotaService::class, function (): QuotaService {
            return new QuotaService($this->app->make(QuotaLedgerRepository::class));
        });

        $this->app->bind(AuthorizerProvisioningQuotaService::class, function (): AuthorizerProvisioningQuotaService {
            return new AuthorizerProvisioningQuotaService(
                $this->app->make(QuotaService::class),
                $this->app->make(AuthorizerProvisioningRepository::class),
            );
        });

        $this->app->bind(AuthorizerConnectionService::class, function (): AuthorizerConnectionService {
            return new AuthorizerConnectionService(
                $this->app->make(AuthorizerConnectionStore::class),
                $this->app->make(AuthorizerAccountStateReader::class),
            );
        });

        $this->app->bind(AuthorizationCompletionService::class, function (): AuthorizationCompletionService {
            return new AuthorizationCompletionService(
                $this->app->make(AuthorizationIntentRepository::class),
                $this->app->make(ComponentAccessTokenService::class),
                $this->app->make(AuthorizerClient::class),
                $this->app->make(AuthorizerAuthorizationRepository::class),
                $this->app->make(AuthorizerAccountBinding::class),
                $this->app->make(TransactionManager::class),
                $this->app->make(AuditLogger::class),
                30,
                $this->app->make(AuthorizerProvisioningRepository::class),
                $this->app->make(ProvisioningJobRepository::class),
            );
        });

        $this->app->bind(AuthorizerProvisioningWorker::class, function (): AuthorizerProvisioningWorker {
            return new AuthorizerProvisioningWorker(
                $this->app->make(ProvisioningJobRepository::class),
                $this->app->make(AuthorizerProvisioningRepository::class),
                $this->app->make(AuthorizerAuthorizationRepository::class),
                $this->app->make(AuthorizerMetadataSyncService::class),
                $this->app->make(AuthorizerOwnershipResolver::class),
                $this->app->make(AuthorizerOwnershipRepository::class),
                $this->app->make(AuthorizerConnectionService::class),
                $this->app->make(AuthorizerProvisioningQuotaService::class),
                $this->app->make(AuthorizerAccountFinalizer::class),
                $this->app->make(AuthorizerMetadataRepository::class),
            );
        });

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
                $this->app->make(AuthorizationCompletionService::class),
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
