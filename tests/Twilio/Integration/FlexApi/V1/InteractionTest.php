<?php

/**
 * This code was generated by
 * \ / _    _  _|   _  _
 * | (_)\/(_)(_|\/| |(/_  v1.0.0
 * /       /
 */

namespace Twilio\Tests\Integration\FlexApi\V1;

use Twilio\Exceptions\DeserializeException;
use Twilio\Exceptions\TwilioException;
use Twilio\Http\Response;
use Twilio\Serialize;
use Twilio\Tests\HolodeckTestCase;
use Twilio\Tests\Request;

class InteractionTest extends HolodeckTestCase {
    public function testFetchRequest(): void {
        $this->holodeck->mock(new Response(500, ''));

        try {
            $this->twilio->flexApi->v1->interaction("KDXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX")->fetch();
        } catch (DeserializeException $e) {}
          catch (TwilioException $e) {}

        $this->assertRequest(new Request(
            'get',
            'https://flex-api.twilio.com/v1/Interactions/KDXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX'
        ));
    }

    public function testFetchResponse(): void {
        $this->holodeck->mock(new Response(
            200,
            '
            {
                "sid": "KDaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
                "channel": {
                    "type": "email"
                },
                "routing": {
                    "properties": {
                        "workflow_sid": "WWxx",
                        "attributes": "WWxx",
                        "task_channel_unique_name": "email",
                        "routing_target": "WKXX",
                        "queue_name": "WQXX"
                    }
                },
                "url": "https://flex-api.twilio.com/v1/Interactions/KDaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
                "links": {
                    "channels": "https://flex-api.twilio.com/v1/Interactions/KDaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/Channels"
                }
            }
            '
        ));

        $actual = $this->twilio->flexApi->v1->interaction("KDXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX")->fetch();

        $this->assertNotNull($actual);
    }

    public function testCreateRequest(): void {
        $this->holodeck->mock(new Response(500, ''));

        try {
            $this->twilio->flexApi->v1->interaction->create([], []);
        } catch (DeserializeException $e) {}
          catch (TwilioException $e) {}

        $values = ['Channel' => Serialize::jsonObject([]), 'Routing' => Serialize::jsonObject([]), ];

        $this->assertRequest(new Request(
            'post',
            'https://flex-api.twilio.com/v1/Interactions',
            null,
            $values
        ));
    }

    public function testCreateResponse(): void {
        $this->holodeck->mock(new Response(
            201,
            '
            {
                "sid": "KDaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
                "channel": {
                    "type": "email"
                },
                "routing": {
                    "properties": {
                        "workflow_sid": "WWxx",
                        "attributes": "WWxx",
                        "task_channel_unique_name": "email",
                        "routing_target": "WKXX",
                        "queue_name": "WQXX"
                    }
                },
                "url": "https://flex-api.twilio.com/v1/Interactions/KDaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
                "links": {
                    "channels": "https://flex-api.twilio.com/v1/Interactions/KDaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/Channels"
                }
            }
            '
        ));

        $actual = $this->twilio->flexApi->v1->interaction->create([], []);

        $this->assertNotNull($actual);
    }
}