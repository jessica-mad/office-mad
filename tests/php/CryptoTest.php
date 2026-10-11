<?php
use MAD_Oficina\Core\Crypto;

class CryptoTest extends MADOF_TestCase {

    public function test_roundtrip(){
        $secret = '1//0g-refresh-token-' . wp_generate_password(40, false);
        $box = Crypto::encrypt($secret);
        $this->assertStringStartsWith(Crypto::PREFIX, $box);
        $this->assertStringNotContainsString($secret, $box);
        $this->assertSame($secret, Crypto::decrypt($box));
    }

    public function test_nonce_is_random(){
        $this->assertNotSame(Crypto::encrypt('same'), Crypto::encrypt('same'));
    }

    public function test_tampered_or_foreign_values_are_rejected(){
        $box = Crypto::encrypt('token');
        $raw = base64_decode(substr($box, strlen(Crypto::PREFIX)));
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        $this->assertNull(Crypto::decrypt(Crypto::PREFIX . base64_encode($raw)));
        $this->assertNull(Crypto::decrypt('plain-text-token'));
        $this->assertNull(Crypto::decrypt(null));
        $this->assertSame('', Crypto::encrypt(''));
    }
}
