<?php

namespace App\Listeners;

use SocialiteProviders\Manager\SocialiteWasCalled;

class SocialiteWasCalledListener
{
    /**
     * Handle the event.
     */
    public function handle(SocialiteWasCalled $event): void
    {
        $event->extendSocialite('vkontakte', \SocialiteProviders\VKontakte\Provider::class);
    }
}
