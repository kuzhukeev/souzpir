<?php

namespace App\Vision\SiteAudit;

use GuzzleHttp\Psr7\Uri;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

final class PublicUrlPolicy
{
    public function __construct(private PublicAddressResolver $resolver) {}

    public function normalize(string $url): string
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            throw new InvalidArgumentException('Нужен публичный HTTP(S)-адрес без управляющих символов.');
        }
        $uri = new Uri($url);
        $host = strtolower(rtrim($uri->getHost(), '.'));
        if ($host === '') {
            throw new InvalidArgumentException('Нужен публичный HTTP(S)-адрес с именем сайта.');
        }
        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false || ! in_array($uri->getScheme(), ['http', 'https'], true)
            || $uri->getUserInfo() !== '' || ! str_contains($ascii, '.')
            || filter_var($ascii, FILTER_VALIDATE_IP) || preg_match('/^[0-9.]+$/', $ascii)
            || ! filter_var($ascii, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || preg_match('/(?:^|\.)(?:localhost|local|internal|invalid|test|onion)$/i', $ascii)
            || ($uri->getPort() !== null && ! in_array($uri->getPort(), [80, 443], true))) {
            throw new InvalidArgumentException('Нужен публичный HTTP(S)-адрес без учётных данных и нестандартного порта.');
        }

        return (string) $uri->withHost($ascii)->withFragment('');
    }

    /** Validate every DNS answer and return one address to pin for this request. */
    public function target(string $url): array
    {
        $url = $this->normalize($url);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $addresses = $this->resolver->addresses($host);
        if ($addresses === []) {
            throw new RuntimeException('У сайта нет проверенного публичного адреса.');
        }
        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                throw new RuntimeException('Сайт разрешается в запрещённый сетевой адрес.');
            }
        }

        return ['url' => $url, 'host' => $host, 'address' => $addresses[0],
            'port' => parse_url($url, PHP_URL_PORT) ?: (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80)];
    }

    private function isPublic(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ! IpUtils::checkIp($address, [
                '0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24',
                '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
            ]);
        }

        return IpUtils::checkIp($address, '2000::/3')
            && ! IpUtils::checkIp($address, ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20']);
    }
}
