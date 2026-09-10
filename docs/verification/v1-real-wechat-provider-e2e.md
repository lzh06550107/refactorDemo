# V1 Real WeChat Provider E2E Gate

This is the final external-provider acceptance gate for V1. It is intentionally **not** part of normal CI because it requires a real WeChat Open Platform third-party platform and a real authorized Official Account or Mini Program.

## 1. Exact revision evidence

Before starting, record the exact code under test:

```bash
git rev-parse HEAD
git status --short
```

`git status --short` must be empty. Keep the HEAD SHA in the release evidence. After any source/config-script commit that changes repository code, rerun the normal CI + MySQL Release Gate before using the new SHA for provider acceptance.

## 2. Provider and deployment prerequisites

Deploy the exact feature revision to a publicly reachable HTTPS host. The WeChat third-party platform must already be configured with the production application callback endpoints for ticket/events and the authorization callback.

Required server-side OpenPlatform credential material remains in the normal application configuration. 不要把任何真实微信密钥写入本验收脚本或仓库。**Do not paste AppSecret, verify Token, EncodingAESKey, component/authorizer access token, refresh token, authorization code, pre-auth code, or callback plaintext into this script, chat, tickets, screenshots, or release notes.**

The E2E runner accepts only an administrator bearer token plus non-provider identifiers. Provider secrets are resolved by the deployed application through its existing credential-reference configuration.

## 3. Prepare the administrator context

The administrator represented by the bearer token must be a member of the test Tenant and must have the OpenPlatform permissions required to start auto provisioning and read provisioning state.

Export only the E2E control values in the current shell:

```bash
export WEPLATFORM_PROVIDER_E2E=1
export WEPLATFORM_PROVIDER_E2E_BASE_URL='https://your-test-host.example.com'
export WEPLATFORM_PROVIDER_E2E_ADMIN_BEARER_TOKEN='<admin-session-token>'
export WEPLATFORM_PROVIDER_E2E_TENANT_ID='<tenant-id>'
export WEPLATFORM_PROVIDER_E2E_COMPONENT_PLATFORM_ID='<component-platform-id>'
```

Do not put the administrator bearer token into source control. Clear it from the shell when acceptance is finished.

## 4. Start real authorization

Run on the deployed application host/revision:

```bash
php tests/ProviderE2E/run.php start
```

The runner calls the existing `auto_provision_account` authorization-intent API and prints one one-time `authorization_url`. Open that URL in a browser and finish WeChat authorization. Do not copy that URL into persistent logs or documentation.

After WeChat redirects to the application's authorization callback, the JSON response contains `data.provisioning_id`. Copy **only** that provisioning id.

## 5. Verify provisioning and final consistency

Run:

```bash
php tests/ProviderE2E/run.php verify '<provisioning_id>'
```

The verifier repeatedly uses the existing provisioning query and `openplatform:provisioning-worker --once` flow. It accepts only these successful final states:

- `provisioned` — first auto-provision created the Account.
- `reconnected` — the same canonical authorizer reused its existing Account.

The verifier also checks the live database invariants: one Account, one canonical ownership, one enabled component provider binding, semantic quota consumption no greater than one, and no quota release on a successful result. A first `provisioned` result must reference exactly one quota consume. A `reconnected` provisioning must not carry a new quota consume reference.

Failure states such as `quota_blocked`, `binding_conflict`, `metadata_failed`, `metadata_type_conflict`, `provision_failed`, or `authorization_inactive` fail the Provider Gate and retain the durable state for diagnosis.

## 6. Required second pass: reconnect/idempotency

After the first `provisioned` pass is green, start authorization **again for the same WeChat authorizer and the same Tenant** and run `verify` on the new provisioning id. The expected result is `reconnected`, not a second Account.

This second pass is the real-provider proof for repeated authorization/idempotency: Account count stays one, ownership stays one, the existing binding is enabled, and quota is not consumed a second time.

## 7. Release evidence

Record only safe evidence:

```text
exact HEAD SHA
CI run id: GREEN
MySQL Release Gate: GREEN
provider type: official_account | wechat_mini_program
first provisioning id: <safe id>
first result: provisioned
reconnect provisioning id: <safe id>
reconnect result: reconnected
final Provider E2E: GREEN
```

Do not record the authorization URL or any WeChat/AppSecret/EncodingAESKey/token/code/plaintext callback material.

Only after the exact HEAD has normal CI + MySQL Release Gate GREEN **and** this real Provider E2E is GREEN should PR #7 move from Draft to Ready and be merged to `main`. After merge, run independent `main` CI before tagging/publishing V1.
