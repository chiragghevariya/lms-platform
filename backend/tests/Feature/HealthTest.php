<?php

it('responds to ping', function () {
    $this->getJson(api('ping'))->assertOk()->assertJsonPath('data.status', 'ok');
});
