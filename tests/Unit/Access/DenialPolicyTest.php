<?php

/**
 * wp-jsonapi: JSON:API 1.1 on the WordPress REST API.
 * Copyright (C) 2026 Polunich
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Polunich\WpJsonApi\Tests\Unit\Access;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Access\DenialPolicy;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Authentication\ChallengeProvider;
use Polunich\WpJsonApi\Authentication\Identity;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ForbiddenException;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\LibraryErrorCatalog;
use Polunich\WpJsonApi\Error\NotFoundException;
use Polunich\WpJsonApi\Error\UnauthorizedException;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Tests\Fixtures\Authentication\FixedChallenges;
use Polunich\WpJsonApi\Tests\Fixtures\Authentication\FixedIdentity;
use Polunich\WpJsonApi\Tests\Fixtures\Authentication\NoChallenges;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(DenialPolicy::class)]
final class DenialPolicyTest extends TestCase
{
    public function testAnAuthenticatedClientThatIsDeniedGets403(): void
    {
        $denial = self::policy(true, [self::basic()])->deny(self::request(), self::schemes());

        self::assertInstanceOf(ForbiddenException::class, $denial);
        self::assertSame(403, $denial->status());
        self::assertSame(ErrorCode::Forbidden, self::onlyDescription($denial)->code);
    }

    public function testAClientThatIsNotAuthenticatedGets401WithTheChallengesOfTheProvider(): void
    {
        $challenges = [self::basic(), new Challenge('Bearer')];

        $denial = self::policy(false, $challenges)->deny(self::request(), self::schemes());

        self::assertInstanceOf(UnauthorizedException::class, $denial);
        self::assertSame(401, $denial->status());
        self::assertSame($challenges, $denial->challenges());
        self::assertSame(ErrorCode::Unauthorized, self::onlyDescription($denial)->code);
    }

    public function testAClientThatIsNotAuthenticatedGets403WhenNoChallengeApplies(): void
    {
        $denial = self::policy(false, [])->deny(self::request(), self::schemes());

        self::assertInstanceOf(ForbiddenException::class, $denial);
        self::assertSame(403, $denial->status());
        self::assertSame(ErrorCode::AuthenticationRequired, self::onlyDescription($denial)->code);
    }

    /**
     * `NoChallenges` is the test fixture, not the provider the library installs by default
     */
    public function testTheDefaultProviderLeavesEveryDenialAt403(): void
    {
        $denial = (new DenialPolicy(new FixedIdentity(false), new NoChallenges()))
            ->deny(self::request(), self::schemes());

        self::assertSame(403, $denial->status());
        self::assertSame(ErrorCode::AuthenticationRequired, self::onlyDescription($denial)->code);
    }

    public function testTheProviderIsAskedForTheRequestThatWasDeniedAndTheSchemesOfItsOperation(): void
    {
        $provider = self::recorder();
        $request = self::request();
        $schemes = [SecurityScheme::restNonce()];

        (new DenialPolicy(new FixedIdentity(false), $provider))->deny($request, $schemes);

        self::assertSame([[$request, $schemes]], $provider->asked);
    }

    public function testTheProviderIsAskedForTheRequestThatCarriedTheForeign401AndTheSchemesOfItsOperation(): void
    {
        $provider = self::recorder();
        $request = self::request();
        $schemes = [SecurityScheme::applicationPasswords()];
        $error = new UnauthorizedException([new ErrorDescription('rest_not_logged_in')]);

        (new DenialPolicy(new FixedIdentity(false), $provider))->rewriteUnauthorized($request, $schemes, $error);

        self::assertSame([[$request, $schemes]], $provider->asked);
    }

    public function testTheProviderIsNotAskedForAnAuthenticatedClient(): void
    {
        $provider = self::recorder();

        (new DenialPolicy(new FixedIdentity(true), $provider))->deny(self::request(), self::schemes());

        self::assertSame([], $provider->asked);
    }

    /**
     * the catalog returns no detail when a placeholder lacks its parameter,
     * so a detail proves that the description carries every parameter its text names
     *
     * @param list<Challenge> $challenges
     */
    #[DataProvider('denials')]
    public function testEveryDenialIsWordedAndNamesNoSource(bool $authenticated, array $challenges): void
    {
        $description = self::onlyDescription(
            self::policy($authenticated, $challenges)->deny(self::request(), self::schemes()),
        );
        $code = $description->code;
        $catalog = new LibraryErrorCatalog();

        self::assertInstanceOf(ErrorCode::class, $code);
        self::assertNotNull($catalog->title($code->value));
        self::assertNotNull($catalog->detail($code->value, $description->parameters));
        self::assertNull($description->pointer);
        self::assertNull($description->parameter);
        self::assertNull($description->header);
    }

    /**
     * @return iterable<string, array{bool, list<Challenge>}>
     */
    public static function denials(): iterable
    {
        yield 'an authenticated client' => [true, []];
        yield 'a client without identity for which a challenge applies' => [false, [self::basic()]];
        yield 'a client without identity for which none applies' => [false, []];
    }

    public function testAForeign401GetsTheChallengesOfTheProvider(): void
    {
        $challenges = [self::basic()];
        $error = new UnauthorizedException([new ErrorDescription('rest_not_logged_in')]);

        $rewritten = self::policy(false, $challenges)->rewriteUnauthorized(self::request(), self::schemes(), $error);

        self::assertInstanceOf(UnauthorizedException::class, $rewritten);
        self::assertSame(401, $rewritten->status());
        self::assertSame($challenges, $rewritten->challenges());
        self::assertSame('rest_not_logged_in', self::onlyDescription($rewritten)->code);
        self::assertSame($error, $rewritten->getPrevious());
    }

    public function testAForeign401Becomes403WithItsCodeWhenNoChallengeApplies(): void
    {
        $error = new UnauthorizedException([new ErrorDescription('rest_not_logged_in')]);

        $rewritten = self::policy(false, [])->rewriteUnauthorized(self::request(), self::schemes(), $error);

        self::assertInstanceOf(ForbiddenException::class, $rewritten);
        self::assertSame(403, $rewritten->status());
        self::assertSame('rest_not_logged_in', self::onlyDescription($rewritten)->code);
        self::assertSame($error, $rewritten->getPrevious());
    }

    public function testA401ThatIsNoUnauthorizedExceptionGoesThroughTheSameRule(): void
    {
        $challenges = [self::basic()];
        $error = new JsonApiException(401, [new ErrorDescription('rest_cannot_edit')]);

        $rewritten = self::policy(false, $challenges)->rewriteUnauthorized(self::request(), self::schemes(), $error);

        self::assertInstanceOf(UnauthorizedException::class, $rewritten);
        self::assertSame($challenges, $rewritten->challenges());
    }

    public function testAProblemThatStatesThe401StatesThe403Instead(): void
    {
        $error = new UnauthorizedException([
            new ErrorDescription('rest_not_logged_in', status: 401),
            new ErrorDescription('rest_forbidden_context', status: 404),
            new ErrorDescription('rest_cannot_edit'),
        ]);

        $rewritten = self::policy(false, [])->rewriteUnauthorized(self::request(), self::schemes(), $error);

        self::assertSame(
            [403, 404, null],
            array_map(
                static function (ErrorDescription $description): ?int {
                    return $description->status;
                },
                $rewritten->descriptions(),
            ),
        );
    }

    /**
     * a problem lowered to 403 inside a 401 would be the contradiction the rewrite exists to avoid
     */
    public function testTheProblemsOfA401ThatKeepsItsStatusAreLeftAsTheyAre(): void
    {
        $error = new UnauthorizedException([
            new ErrorDescription('rest_not_logged_in', status: 401),
            new ErrorDescription('rest_forbidden_context', status: 404),
            new ErrorDescription('rest_cannot_edit'),
        ]);

        $rewritten = self::policy(false, [self::basic()])
            ->rewriteUnauthorized(self::request(), self::schemes(), $error);

        self::assertSame(401, $rewritten->status());
        self::assertSame(
            [401, 404, null],
            array_map(
                static function (ErrorDescription $description): ?int {
                    return $description->status;
                },
                $rewritten->descriptions(),
            ),
        );
    }

    #[DataProvider('otherErrors')]
    public function testAnErrorOfAnotherStatusIsReturnedAsItStands(JsonApiException $error): void
    {
        self::assertSame(
            $error,
            self::policy(false, [self::basic()])->rewriteUnauthorized(self::request(), self::schemes(), $error),
        );
    }

    /**
     * @return iterable<string, array{JsonApiException}>
     */
    public static function otherErrors(): iterable
    {
        yield 'a 403' => [new ForbiddenException([new ErrorDescription(ErrorCode::Forbidden)])];
        yield 'a 404' => [new NotFoundException([new ErrorDescription(ErrorCode::NotFound)])];
        yield 'a 500' => [new JsonApiException(500, [new ErrorDescription(ErrorCode::InternalError)])];
    }

    public function testA401ThatAlreadyCarriesAChallengeIsReturnedAsItStands(): void
    {
        $error = new UnauthorizedException([new ErrorDescription(ErrorCode::Unauthorized)], [new Challenge('Bearer')]);

        self::assertSame(
            $error,
            self::policy(false, [self::basic()])->rewriteUnauthorized(self::request(), self::schemes(), $error),
        );
    }

    public function testTheIdentityIsNotReadWhenA401IsRewritten(): void
    {
        $identity = new class implements Identity {
            public int $asked = 0;

            #[Override]
            public function isAuthenticated(): bool
            {
                ++$this->asked;

                return true;
            }
        };
        $error = new UnauthorizedException([new ErrorDescription('rest_not_logged_in')]);

        $rewritten = (new DenialPolicy($identity, new FixedChallenges([self::basic()])))
            ->rewriteUnauthorized(self::request(), self::schemes(), $error);

        self::assertSame(0, $identity->asked);
        self::assertSame(401, $rewritten->status());
    }

    public function testThePolicyIsInternalAndImmutable(): void
    {
        $policy = new ReflectionClass(DenialPolicy::class);

        self::assertStringContainsString('@internal', (string) $policy->getDocComment());
        self::assertTrue($policy->isFinal());
        self::assertTrue($policy->isReadOnly());
    }

    /**
     * @param list<Challenge> $challenges
     */
    private static function policy(bool $authenticated, array $challenges): DenialPolicy
    {
        return new DenialPolicy(new FixedIdentity($authenticated), new FixedChallenges($challenges));
    }

    /**
     * @return ChallengeProvider&object{asked: list<array{ServerRequest, list<SecurityScheme>}>}
     */
    private static function recorder(): ChallengeProvider
    {
        return new class implements ChallengeProvider {
            /**
             * @var list<array{ServerRequest, list<SecurityScheme>}>
             */
            public array $asked = [];

            /**
             * @return list<Challenge>
             */
            #[Override]
            public function challenges(ServerRequest $request, array $schemes): array
            {
                $this->asked[] = [$request, $schemes];

                return [];
            }
        };
    }

    /**
     * @return list<SecurityScheme>
     */
    private static function schemes(): array
    {
        return [SecurityScheme::applicationPasswords()];
    }

    private static function basic(): Challenge
    {
        return new Challenge('Basic', ['realm' => 'WordPress']);
    }

    private static function request(): ServerRequest
    {
        return new ServerRequest('GET', new Headers(['Authorization' => 'Basic dXNlcjpwYXNz']), [], [], '');
    }

    private static function onlyDescription(JsonApiException $error): ErrorDescription
    {
        self::assertCount(1, $error->descriptions());

        return $error->descriptions()[0];
    }
}
