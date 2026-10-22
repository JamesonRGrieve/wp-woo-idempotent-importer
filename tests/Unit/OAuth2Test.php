<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace IdempotentImporter\Tests\Unit;

use IdempotentImporter\Http\HttpResponse;
use IdempotentImporter\Source\CredentialsRejected;
use IdempotentImporter\Source\OAuth2\BearerAuthorizer;
use IdempotentImporter\Source\OAuth2\ClientAuth;
use IdempotentImporter\Source\OAuth2\OAuth2Client;
use IdempotentImporter\Source\OAuth2\OAuthApp;
use IdempotentImporter\Source\OAuth2\TokenSet;
use IdempotentImporter\Source\Pkce;
use IdempotentImporter\Source\SourceError;
use IdempotentImporter\Tests\Support\InMemoryTokens;
use IdempotentImporter\Tests\Support\RecordingSleeper;
use IdempotentImporter\Tests\Support\ScriptedTransport;
use PHPUnit\Framework\TestCase;

final class OAuth2Test extends TestCase
{
    private const AUTHORIZE = 'https://vendor.test/oauth/authorize';
    private const TOKEN = 'https://vendor.test/oauth/token';
    private const REDIRECT = 'https://shop.example/wp-admin/admin-post.php?action=iim_oauth_callback';

    public function testTheAuthorizationUrlAsksForTheReadScopesWithStateAndPkce(): void
    {
        $client = self::client(self::app(pkce: true, extra: ['access_type' => 'offline']), new ScriptedTransport(), new InMemoryTokens());

        $url = $client->authorizationUrl(self::REDIRECT, 'state-1', 'verifier-1');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertStringStartsWith(self::AUTHORIZE . '?', $url);
        self::assertSame([
            'response_type' => 'code',
            'client_id' => 'client-1',
            'redirect_uri' => self::REDIRECT,
            'state' => 'state-1',
            'scope' => 'contacts.read deals.read',
            'code_challenge' => Pkce::challenge('verifier-1'),
            'code_challenge_method' => 'S256',
            'access_type' => 'offline',
        ], $query);
    }

    public function testWithoutPkceNoChallengeIsSent(): void
    {
        $url = self::client(self::app(), new ScriptedTransport(), new InMemoryTokens())->authorizationUrl(self::REDIRECT, 's', 'v');

        self::assertStringNotContainsString('code_challenge', $url);
    }

    public function testTheCodeIsTradedForTokensWhichAreKeptWithWhatRequestsNeed(): void
    {
        $tokens = new InMemoryTokens();
        $transport = new ScriptedTransport(null, [self::tokenResponse(['access_token' => 'a1', 'refresh_token' => 'r1', 'expires_in' => 3600, 'instance_url' => 'https://acme.my.salesforce.test'])]);
        $client = new OAuth2Client(self::app(pkce: true), $transport, $tokens, new RecordingSleeper(), ['instance_url']);

        $set = $client->exchange('code-1', 'verifier-1', self::REDIRECT, ['realmId' => '9130']);

        self::assertSame(['a1', 'r1', (int) RecordingSleeper::START + 3600], [$set->accessToken, $set->refreshToken, $set->expiresAt]);
        self::assertSame(['realmId' => '9130', 'instance_url' => 'https://acme.my.salesforce.test'], $set->extras);
        self::assertSame([$set], $tokens->saved);
        parse_str($transport->requests[0]['body'], $form);
        self::assertSame(['grant_type' => 'authorization_code', 'code' => 'code-1', 'redirect_uri' => self::REDIRECT, 'code_verifier' => 'verifier-1', 'client_id' => 'client-1', 'client_secret' => 'secret-1'], $form);
        self::assertSame(self::TOKEN, $transport->requests[0]['url']);
    }

    public function testAnAppThatAuthenticatesWithHttpBasicSendsNoSecretInTheForm(): void
    {
        $transport = new ScriptedTransport(null, [self::tokenResponse(['access_token' => 'a1'])]);
        $client = self::client(self::app(auth: ClientAuth::Basic), $transport, new InMemoryTokens());

        $client->exchange('code-1', 'v', self::REDIRECT);

        self::assertSame('Basic ' . base64_encode('client-1:secret-1'), $transport->requests[0]['headers']['Authorization']);
        self::assertStringNotContainsString('client_secret', $transport->requests[0]['body']);
    }

    public function testAJsonAppSendsJsonTokenRequestsAndARefreshNamesTheRedirectUri(): void
    {
        $tokens = new InMemoryTokens();
        $transport = new ScriptedTransport(null, [
            self::tokenResponse(['access_token' => 'a1', 'refresh_token' => 'r1', 'expires_in' => 3600]),
            self::tokenResponse(['access_token' => 'a2', 'refresh_token' => 'r2', 'expires_in' => 3600]),
        ]);
        $app = new OAuthApp('Vendor', self::AUTHORIZE, self::TOKEN, 'client-1', 'secret-1', [], ClientAuth::Json, refreshNamesRedirectUri: true);
        $client = self::client($app, $transport, $tokens);

        $client->exchange('code-1', 'v', self::REDIRECT);
        $client->refresh();

        self::assertSame('application/json', $transport->requests[0]['headers']['Content-Type']);
        self::assertSame(['grant_type' => 'authorization_code', 'code' => 'code-1', 'redirect_uri' => self::REDIRECT, 'client_id' => 'client-1', 'client_secret' => 'secret-1'], json_decode($transport->requests[0]['body'], true));
        self::assertSame(['grant_type' => 'refresh_token', 'refresh_token' => 'r1', 'redirect_uri' => self::REDIRECT, 'client_id' => 'client-1', 'client_secret' => 'secret-1'], json_decode($transport->requests[1]['body'], true));
    }

    public function testAnAccessTokenAboutToExpireIsRefreshedAndARotatedRefreshTokenReplacesTheOld(): void
    {
        $clock = new RecordingSleeper();
        $tokens = new InMemoryTokens(new TokenSet('a1', 'r1', (int) $clock->now() + 60, ['instance_url' => 'https://x.test']));
        $transport = new ScriptedTransport(null, [self::tokenResponse(['access_token' => 'a2', 'refresh_token' => 'r2', 'expires_in' => 3600])]);
        $client = new OAuth2Client(self::app(), $transport, $tokens, $clock);

        $current = $client->current();

        self::assertSame(['a2', 'r2'], [$current->accessToken, $current->refreshToken], 'within the expiry margin');
        self::assertSame('https://x.test', $current->extra('instance_url'), 'what the first response said is kept');
        parse_str($transport->requests[0]['body'], $form);
        self::assertSame('refresh_token', $form['grant_type']);
        self::assertSame('r1', $form['refresh_token']);
    }

    public function testATokenStillValidIsUsedAsIs(): void
    {
        $clock = new RecordingSleeper();
        $client = new OAuth2Client(self::app(), new ScriptedTransport(), new InMemoryTokens(new TokenSet('a1', 'r1', (int) $clock->now() + 3600)), $clock);

        self::assertSame('a1', $client->current()->accessToken);
    }

    public function testARefreshResponseWithoutANewRefreshTokenKeepsTheOldOne(): void
    {
        $tokens = new InMemoryTokens(new TokenSet('a1', 'r1', 0));
        $client = self::client(self::app(), new ScriptedTransport(null, [self::tokenResponse(['access_token' => 'a2', 'expires_in' => 3600])]), $tokens);

        self::assertSame('r1', $client->refresh()->refreshToken);
    }

    public function testARefusedRefreshStopsTheRun(): void
    {
        $client = self::client(self::app(), new ScriptedTransport(null, [new HttpResponse(400, '{"error":"invalid_grant"}')]), new InMemoryTokens(new TokenSet('a1', 'r1', 0)));

        $this->expectException(CredentialsRejected::class);
        $client->current();
    }

    public function testAnAccountNeverConnectedIsAnError(): void
    {
        $client = self::client(self::app(), new ScriptedTransport(), new InMemoryTokens());

        self::assertFalse($client->isConnected());
        $this->expectException(SourceError::class);
        $client->current();
    }

    public function testAnAppWithoutCredentialsIsNotConfigured(): void
    {
        $this->expectException(SourceError::class);
        new OAuthApp('Vendor', self::AUTHORIZE, self::TOKEN, '', '', []);
    }

    public function testTheBearerAuthorizerRenewsOnceThroughTheRefreshToken(): void
    {
        $clock = new RecordingSleeper();
        $tokens = new InMemoryTokens(new TokenSet('a1', 'r1', (int) $clock->now() + 3600, ['api_domain' => 'https://www.zohoapis.test']));
        $client = new OAuth2Client(self::app(), new ScriptedTransport(null, [self::tokenResponse(['access_token' => 'a2', 'expires_in' => 3600])]), $tokens, $clock);
        $authorizer = BearerAuthorizer::oauth($client, ['Accept-Version' => '2']);

        self::assertSame(['Accept-Version' => '2', 'Authorization' => 'Bearer a1'], $authorizer->headers());
        self::assertTrue($authorizer->renew());
        self::assertSame('Bearer a2', $authorizer->headers()['Authorization']);
        self::assertSame('https://www.zohoapis.test', $authorizer->extra('api_domain'));
    }

    public function testAVendorsOwnAuthorizationSchemeIsSent(): void
    {
        $clock = new RecordingSleeper();
        $tokens = new InMemoryTokens(new TokenSet('a1', 'r1', (int) $clock->now() + 3600, []));
        $client = new OAuth2Client(self::app(), new ScriptedTransport(null, []), $tokens, $clock);

        self::assertSame(['Authorization' => 'Zoho-oauthtoken a1'], BearerAuthorizer::oauth($client, [], 'Zoho-oauthtoken')->headers());
        self::assertSame(['Authorization' => 'Zoho-oauthtoken t'], BearerAuthorizer::token('t', 'Zoho CRM', [], 'Zoho-oauthtoken')->headers());
    }

    public function testAStaticTokenHasNothingToRenew(): void
    {
        $authorizer = BearerAuthorizer::token('private-app-token', 'Vendor');

        self::assertSame(['Authorization' => 'Bearer private-app-token'], $authorizer->headers());
        self::assertFalse($authorizer->renew());
        self::assertNull($authorizer->extra('instance_url'));
        $this->expectException(SourceError::class);
        BearerAuthorizer::token('', 'Vendor');
    }

    public function testTokensRoundTripThroughStorage(): void
    {
        $set = new TokenSet('a', 'r', 12, ['realmId' => '1']);

        self::assertEquals($set, TokenSet::fromArray($set->toArray()));
        self::assertNull(TokenSet::fromArray(['refresh_token' => 'r']));
    }

    /** @param array<string, string> $extra */
    private static function app(bool $pkce = false, ClientAuth $auth = ClientAuth::Form, array $extra = []): OAuthApp
    {
        return new OAuthApp('Vendor', self::AUTHORIZE, self::TOKEN, 'client-1', 'secret-1', ['contacts.read', 'deals.read'], $auth, $pkce, $extra);
    }

    private static function client(OAuthApp $app, ScriptedTransport $transport, InMemoryTokens $tokens): OAuth2Client
    {
        return new OAuth2Client($app, $transport, $tokens, new RecordingSleeper());
    }

    /** @param array<string, mixed> $body */
    private static function tokenResponse(array $body): HttpResponse
    {
        return new HttpResponse(200, (string) json_encode($body));
    }
}
