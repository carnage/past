<?php
declare(strict_types=1);
namespace ParagonIE\PAST;

use ParagonIE\ConstantTime\Base64UrlSafe;
use ParagonIE\PAST\Exception\{
    InvalidKeyException,
    PastException
};
use ParagonIE\PAST\Keys\{
    AsymmetricPublicKey,
    AsymmetricSecretKey,
    SymmetricAuthenticationKey,
    SymmetricEncryptionKey
};
use ParagonIE\PAST\Protocol\{
    Version1,
    Version2
};
use ParagonIE\PAST\Traits\RegisteredClaims;

/**
 * Class Parser
 * @package ParagonIE\PAST
 * @psalm-suppress PropertyNotSetInConstructor
 */
class Parser
{
    use RegisteredClaims;

    const DEFAULT_VERSION_ALLOW = [Version1::HEADER, Version2::HEADER];

    /** @var array<int, string> */
    protected $allowedVersions;

    /**
     * @var KeyInterface $key
     */
    protected $key;

    /** @var string $purpose */
    protected $purpose;

    /**
     * Parser constructor.
     *
     * @param array<int, string> $allowedVersions
     * @param string $purpose
     * @param KeyInterface|null $key
     * @throws PastException
     */
    public function __construct(
        array $allowedVersions = self::DEFAULT_VERSION_ALLOW,
        string $purpose = '',
        KeyInterface $key = null
    ) {
        $this->allowedVersions = $allowedVersions;
        $this->purpose = $purpose;
        if (!\is_null($key)) {
            $this->setKey($key, true);
        }
    }

    /**
     * @param string $tainted
     * @return JsonToken
     * @throws PastException
     */
    public function parse(string $tainted): JsonToken
    {
        /** @var array<int, string> $pieces */
        $pieces = \explode('.', $tainted);
        if (\count($pieces) < 3) {
            throw new PastException('Truncated or invalid token');
        }
        $header = $pieces[0];
        if (!\in_array($header, $this->allowedVersions, true)) {
            throw new PastException('Disallowed or unsupported version');
        }

        switch ($header) {
            case Version1::HEADER:
                $protocol = Version1::class;
                break;
            case Version2::HEADER:
                $protocol = Version2::class;
                break;
            default:
                throw new PastException('Disallowed or unsupported version');
        }
        /** @var ProtocolInterface $protocol */
        /** @var string $purpose */
        $footer = '';
        $purpose = $pieces[1];
        if (!empty($this->purpose)) {
            if (!\hash_equals($this->purpose, $purpose)) {
                throw new PastException('Disallowed or unsupported purpose');
            }
        }
        switch ($purpose) {
            case 'auth':
                if (!($this->key instanceof SymmetricAuthenticationKey)) {
                    throw new PastException('Invalid key type');
                }
                $footer = (count($pieces) > 3)
                    ? Base64UrlSafe::decode($pieces[3])
                    : '';
                try {
                    /** @var string $decoded */
                    $decoded = $protocol::authVerify($tainted, $this->key, $footer);
                } catch (\Throwable $ex) {
                    throw new PastException('An error occurred', 0, $ex);
                }
                break;
            case 'enc':
                if (!($this->key instanceof SymmetricEncryptionKey)) {
                    throw new PastException('Invalid key type');
                }
                $footer = (count($pieces) > 3)
                    ? Base64UrlSafe::decode($pieces[3])
                    : '';
                try {
                    /** @var string $decoded */
                    $decoded = $protocol::decrypt($tainted, $this->key, $footer);
                } catch (\Throwable $ex) {
                    throw new PastException('An error occurred', 0, $ex);
                }
                break;
            case 'seal':
                if (!($this->key instanceof AsymmetricSecretKey)) {
                    throw new PastException('Invalid key type');
                }
                $footer = (count($pieces) > 4)
                    ? Base64UrlSafe::decode($pieces[4])
                    : '';
                try {
                    /** @var string $decoded */
                    $decoded = $protocol::unseal($tainted, $this->key, $footer);
                } catch (\Throwable $ex) {
                    throw new PastException('An error occurred', 0, $ex);
                }
                break;
            case 'sign':
                if (!($this->key instanceof AsymmetricPublicKey)) {
                    throw new PastException('Invalid key type');
                }
                $footer = (count($pieces) > 4)
                    ? Base64UrlSafe::decode($pieces[4])
                    : '';
                try {
                    /** @var string $decoded */
                    $decoded = $protocol::signVerify($tainted, $this->key, $footer);
                } catch (\Throwable $ex) {
                    throw new PastException('An error occurred', 0, $ex);
                }
                break;
        }
        if (!isset($decoded)) {
            throw new PastException('Unsupported purpose or version.');
        }
        /** @var array $claims */
        $claims = \json_decode((string) $decoded, true);
        if (!\is_array($claims)) {
            throw new PastException('Not a JSON token.');
        }
        return (new JsonToken())
            ->setVersion($header)
            ->setPurpose($purpose)
            ->setKey($this->key)
            ->setFooter($footer)
            ->setClaims($claims);
    }
    /**
     * @param KeyInterface $key
     * @param bool $checkPurpose
     * @return self
     * @throws PastException
     */
    public function setKey(KeyInterface $key, bool $checkPurpose = false): self
    {
        if ($checkPurpose) {
            switch ($this->purpose) {
                case 'auth':
                    if (!($key instanceof SymmetricAuthenticationKey)) {
                        throw new InvalidKeyException(
                            'Invalid key type. Expected ' . SymmetricAuthenticationKey::class . ', got ' . \get_class($key)
                        );
                    }
                    break;
                case 'enc':
                    if (!($key instanceof SymmetricEncryptionKey)) {
                        throw new InvalidKeyException(
                            'Invalid key type. Expected ' . SymmetricEncryptionKey::class . ', got ' . \get_class($key)
                        );
                    }
                    break;
                case 'seal':
                    if (!($key instanceof AsymmetricSecretKey)) {
                        throw new InvalidKeyException(
                            'Invalid key type. Expected ' . AsymmetricSecretKey::class . ', got ' . \get_class($key)
                        );
                    }
                    break;
                case 'sign':
                    if (!($key instanceof AsymmetricPublicKey)) {
                        throw new InvalidKeyException(
                            'Invalid key type. Expected ' . AsymmetricPublicKey::class . ', got ' . \get_class($key)
                        );
                    }
                    break;
                default:
                    throw new InvalidKeyException('Unknown purpose');
            }
        }
        $this->key = $key;
        return $this;
    }

    /**
     * @param string $purpose
     * @param bool $checkKeyType
     * @return self
     * @throws PastException
     */
    public function setPurpose(string $purpose, bool $checkKeyType = false): self
    {
        if ($checkKeyType) {
            $keyType = \get_class($this->key);
            switch ($keyType) {
                case SymmetricAuthenticationKey::class:
                    if (!\hash_equals('auth', $purpose)) {
                        throw new PastException(
                            'Invalid purpose. Expected auth, got ' . $purpose
                        );
                    }
                    break;
                case SymmetricEncryptionKey::class:
                    if (!\hash_equals('enc', $purpose)) {
                        throw new PastException(
                            'Invalid purpose. Expected enc, got ' . $purpose
                        );
                    }
                    break;
                case AsymmetricSecretKey::class:
                    if (!\hash_equals('seal', $purpose)) {
                        throw new PastException(
                            'Invalid purpose. Expected seal, got ' . $purpose
                        );
                    }
                    break;
                case AsymmetricPublicKey::class:
                    if (!\hash_equals('sign', $purpose)) {
                        throw new PastException(
                            'Invalid purpose. Expected sign, got ' . $purpose
                        );
                    }
                    break;
                default:
                    throw new PastException('Unknown purpose: ' . $purpose);
            }
        }

        $this->purpose = $purpose;
        return $this;
    }
}