<?php

namespace KleeGroup\FranceConnectBundle\Manager;

use KleeGroup\FranceConnectBundle\Manager\Exception\Exception;
use KleeGroup\FranceConnectBundle\Manager\Exception\SecurityException;
use KleeGroup\FranceConnectBundle\Security\Core\Authentication\Token\FranceConnectToken;
use KleeGroup\FranceConnectBundle\Security\Core\Authorization\Voter\FranceConnectAuthenticatedVoter;
use Namshi\JOSE\SimpleJWS;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Http\Session\SessionAuthenticationStrategyInterface;
use Unirest\Request;

/**
 * Class ContextService
 *
 * @package KleeGroup\FranceConnectBundle\Manager
 */
class ContextService implements ContextServiceInterface
{
    const OPENID_SESSION_TOKEN = "open_id_session_token";
    const OPENID_SESSION_NONCE = "open_id_session_nonce";
    const ID_TOKEN_HINT        = "open_id_token_hint";
    
    private LoggerInterface $logger;
    private TokenStorageInterface $tokenStorage;
    private SessionAuthenticationStrategyInterface $sessionStrategy;
    private RequestStack $requestStack;
    private string $clientId;
    private string $clientSecret;
    private string $fcBaseUrl;
    private array $scopes;
    private string $callbackUrl;
    private string $logoutUrl;
    private ?string $proxyHost;
    private ?int $proxyPort;
    private array $providersKeys;

    public function __construct(
        LoggerInterface $logger,
        RouterInterface $router,
        SessionAuthenticationStrategyInterface $sessionStrategy,
        TokenStorageInterface $tokenStorage,
        RequestStack $requestStack,
        string $clientId,
        string $clientSecret,
        string $fcBaseUrl,
        array $scopes,
        string $callbackType,
        string $callbackValue,
        string $logoutType,
        string $logoutValue,
        array $providersKeys,
        string $proxy = null,
        int $proxyPort = null
    ) {
        $this->logger = $logger;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->fcBaseUrl = $fcBaseUrl;
        $this->scopes = $scopes;

        //  Callback URL
        try {
            if ($callbackType === 'route') {
                $this->callbackUrl = $router->generate($callbackValue, [], UrlGeneratorInterface::ABSOLUTE_URL);
            } else {
                $this->callbackUrl = $callbackValue;
            }
        } catch (RouteNotFoundException $e) {
            throw new Exception("Callback route name is invalid", 500, $e);
        }

        //  Logout URL
        try {
            if ($logoutType === 'route') {
                $this->logoutUrl = $router->generate($logoutValue, [], UrlGeneratorInterface::ABSOLUTE_URL);
            } else {
                $this->logoutUrl = $logoutValue;
            }
        } catch (RouteNotFoundException $e) {
            throw new Exception("Logout route name is invalid", 500, $e);
        }

        $this->proxyPort = $proxyPort;
        $this->proxyHost = $proxy;
        $this->tokenStorage = $tokenStorage;
        $this->sessionStrategy = $sessionStrategy;
        $this->requestStack = $requestStack;
        $this->providersKeys = $providersKeys;
    }

    /**
     * @inheritdoc
     */
    public function generateAuthorizationURL(): string
    {
        $this->logger->debug('Set session tokens');
        $this->requestStack->getSession()->set(static::OPENID_SESSION_TOKEN, $this->getRandomToken());
        $this->requestStack->getSession()->set(static::OPENID_SESSION_NONCE, $this->getRandomToken());
        
        $this->logger->debug('Generate Query String.');
        $params = [
            'response_type' => 'code',
            'client_id'     => $this->clientId,
            'scope'         => implode(' ', $this->scopes),
            'redirect_uri'  => $this->callbackUrl,
            'nonce'         => $this->requestStack->getSession()->get(static::OPENID_SESSION_NONCE),
            'state'         => urlencode('token={'.$this->requestStack->getSession()->get(static::OPENID_SESSION_TOKEN).'}'),
        ];

        return $this->fcBaseUrl.'authorize?'.http_build_query($params);
    }

    /**
     * Generate random string.
     *
     * @return string
     */
    private function getRandomToken(): string
    {
        return sha1(random_int(0, mt_getrandmax()));
    }

    /**
     * Returns data provided by FranceConnect.
     *
     * @param array $params query string parameter
     *
     * @return string data provided by FranceConnect (json)
     * @throws Exception General exception
     * @throws SecurityException An exception may be thrown if a security check has failed
     */
    public function getUserInfo(array $params): mixed
    {
        $this->logger->debug('Get User Info.');
        if (array_key_exists("error", $params)) {
            $this->logger->error(
                $params["error"].array_key_exists("error_description", $params) ? $params["error_description"] : ''
            );
            throw new Exception('FranceConnect error => '.$params["error"]);
        }

        $this->verifyState($params['state']);
        $accessToken = $this->getAccessToken($params['code']);
        $userInfo = $this->getInfos($accessToken);
        $userInfo['access_token'] = $accessToken;
        
        $token = new FranceConnectToken(
            $userInfo,
            [
                FranceConnectAuthenticatedVoter::IS_FRANCE_CONNECT_AUTHENTICATED,
                AuthenticatedVoter::IS_AUTHENTICATED_ANONYMOUSLY,
            ]
        );
        $request = $this->requestStack->getCurrentRequest();

        if (null !== $request) {
            $this->sessionStrategy->onAuthentication($request, $token);
        }

        $this->tokenStorage->setToken($token);
        foreach ($this->providersKeys as $key) {
            $this->requestStack->getSession()->set('_security_'.$key, serialize($token));
        }

        return json_encode($userInfo, true);
    }

    /**
     * Check state parameter for security reason.
     *
     * @param $state
     *
     * @throws SecurityException
     */
    private function verifyState($state): void
    {
        $this->logger->debug('Verify parameter state.');
        $state = urldecode($state);
        $stateArray = [];
        parse_str($state, $stateArray);
        $token = $stateArray['token'];
        $token = preg_replace('~{~', '', $token, 1);
        $token = preg_replace('~}~', '', $token, 1);
        
        if ($token != $this->requestStack->getSession()->get(static::OPENID_SESSION_TOKEN)) {
            $this->logger->error('The value of the parameter STATE is not equal to the one which is expected');
            throw new SecurityException("The token is invalid.");
        }
    }

    /**
     * Get Access Token.
     *
     * @param string $code authorization code
     *
     * @return string access token
     * @throws SecurityException
     * @throws Exception
     */
    private function getAccessToken($code): string
    {
        $this->logger->debug('Get Access Token.');
        $this->initRequest();
        $tokenUrl = $this->fcBaseUrl.'token';
        $postData = [
            "grant_type"    => "authorization_code",
            "redirect_uri"  => $this->callbackUrl,
            "client_id"     => $this->clientId,
            "client_secret" => $this->clientSecret,
            "code"          => $code,
        ];
        $this->logger->debug('POST Data to FranceConnect.');
        $this->setPostFields($postData);
        $response = Request::post($tokenUrl);
        
        // check status code
        if ($response->code !== Response::HTTP_OK) {
            $resultAray = $response->body;
            $description = array_key_exists(
                "error_description",
                $resultAray
            ) ? $resultAray["error_description"] : '';
            $this->logger->error(
                $resultAray["error"].$description
            );
            throw new Exception("FranceConnectError".$response->code." msg = ".$response->raw_body);
        }
        
        $resultAray = $response->body;
        $idToken = $resultAray['id_token'];
        $this->requestStack->getSession()->set(static::ID_TOKEN_HINT, $idToken);
        $allPart = explode(".", $idToken);
        $payload = json_decode(base64_decode($allPart[1]), true);
        
        // check nonce parameter
        if ($payload['nonce'] != $this->requestStack->getSession()->get(static::OPENID_SESSION_NONCE)) {
            $this->logger->error('The value of the parameter NONCE is not equal to the one which is expected');
            throw new SecurityException("The nonce parameter is invalid");
        }
        // verify the signature of jwt
        $this->logger->debug('Check JWT signature.');
        $jws = SimpleJWS::load($idToken);
        if (!$jws->verify($this->clientSecret)) {
            $this->logger->error('The signature of the JWT is not valid.');
            throw new SecurityException("JWS is invalid");
        }
        
        $this->requestStack->getSession()->remove(static::OPENID_SESSION_NONCE);
        
        return $resultAray['access_token'];
    }

    /**
     * Prepare request.
     */
    private function initRequest(): void
    {
        Request::clearCurlOpts();
        Request::clearDefaultHeaders();
        // => jsonOpts équivaut à "json_decode($result, true)"
        Request::jsonOpts(true);
        if (!empty($this->proxyHost)) {
            Request::proxy($this->proxyHost, $this->proxyPort);
        }
    }

    /**
     * set post fields.
     *
     * @param array $postData
     */
    private function setPostFields(array $postData)
    {
        $pd = [];
        foreach ($postData as $k => $v) {
            $pd[] = "$k=$v";
        }
        $pd = implode("&", $pd);
        Request::curlOpt(CURLOPT_POST, true);
        Request::curlOpt(CURLOPT_POSTFIELDS, $pd);
        Request::curlOpt(CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    }

    /**
     * Last call to FranceConnect to get data.
     *
     * @param $accessToken
     *
     * @return mixed
     * @throws Exception
     */
    private function getInfos($accessToken): mixed
    {
        $this->logger->debug('Get Infos.');
        $this->initRequest();
        $headers = [
            "Authorization" => "Bearer $accessToken",
        ];
        $userInfoUrl = $this->fcBaseUrl."userinfo?schema=openid";
        $response = Request::get($userInfoUrl, $headers);
        if ($response->code !== Response::HTTP_OK) {
            $resultArray = $response->body;
            $messageErreur = $resultArray['error'];
            $this->logger->error($messageErreur);
            throw new Exception("Erreur lors de la récupération des infos sur le serveur OpenID : ".$messageErreur);
        }

        return $response->body;
    }

    /**
     * @inheritdoc
     */
    public function generateLogoutURL(): string
    {
        $this->logger->debug('Generate Query String.');
        $params = [
            'post_logout_redirect_uri' => $this->logoutUrl,
            'id_token_hint'            => $this->requestStack->getSession()->get(static::ID_TOKEN_HINT),
        ];

        $this->logger->debug('Remove session token');
        $this->requestStack->getSession()->clear();
        
        return $this->fcBaseUrl.'logout?'.http_build_query($params);
    }
    
}
