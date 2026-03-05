<?php
namespace Yastore\Checkout;

use Bitrix\Main\Config\Option;

class Tokens
{
    private $secretKey;
    private $siteId;
    private $algorithm = 'HS256';
    static $moduleId = 'yastore.checkout';

    public function __construct()
    {
        $this->siteId = Option::get(self::$moduleId, 'SITE_ID', '');
        $this->secretKey = Option::get(self::$moduleId, 'JWT_TOKEN', '');
    }

    public function generateToken($expTime = 3600)
    {
        $payload = [
            'iat' => time(),
            'exp' => time() + $expTime,
            'sub' => $this->siteId,
        ];

        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'HS256',
            'typ' => 'JWT'
        ]));

        $payload = $this->base64UrlEncode(json_encode($payload));

        $signature = $this->base64UrlEncode(
            hash_hmac('sha256', "$header.$payload", $this->secretKey, true)
        );

        return "$header.$payload.$signature";
    }

    public function verifyToken($token)
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        list($header, $payload, $signature) = $parts;

        $validSignature = $this->base64UrlEncode(
            hash_hmac('sha256', "$header.$payload", $this->secretKey, true)
        );

        if ($signature !== $validSignature) {
            return false;
        }

        $decodedPayload = json_decode($this->base64UrlDecode($payload), true);

        if (isset($decodedPayload['exp']) && $decodedPayload['exp'] < time()) {
            return false;
        }

        return $decodedPayload;
    }

    private function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode($data)
    {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }
}
