<?php

/**
 * This code was generated by
 * \ / _    _  _|   _  _
 * | (_)\/(_)(_|\/| |(/_  v1.0.0
 * /       /
 */

namespace Twilio\Tests\Integration\Authy\V1;

use Twilio\Exceptions\DeserializeException;
use Twilio\Exceptions\TwilioException;
use Twilio\Http\Response;
use Twilio\Tests\HolodeckTestCase;
use Twilio\Tests\Request;

class FormTest extends HolodeckTestCase {
    public function testFetchRequest() {
        $this->holodeck->mock(new Response(500, ''));

        try {
            $this->twilio->authy->v1->forms("form-app-push")->fetch();
        } catch (DeserializeException $e) {}
          catch (TwilioException $e) {}

        $this->assertRequest(new Request(
            'get',
            'https://authy.twilio.com/v1/Forms/form-app-push'
        ));
    }

    public function testFetchResponse() {
        $this->holodeck->mock(new Response(
            200,
            '
            {
                "type": "form-sms",
                "forms": {
                    "create_factor": {},
                    "verify_factor": {},
                    "create_challenge": {}
                },
                "form_meta": {},
                "url": "https://authy.twilio.com/v1/Forms/form-sms"
            }
            '
        ));

        $actual = $this->twilio->authy->v1->forms("form-app-push")->fetch();

        $this->assertNotNull($actual);
    }
}