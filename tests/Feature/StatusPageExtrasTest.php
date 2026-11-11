<?php

namespace Tests\Feature;

use App\Filament\App\Resources\StatusPages\Pages\EditStatusPage;
use App\Filament\App\Resources\StatusPages\RelationManagers\AnnouncementsRelationManager;
use App\Mail\StatusPageNoticeMail;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageAnnouncement;
use App\Models\StatusPageSubscriber;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Monitoring\MonitorCheckRunner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class StatusPageExtrasTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workspace $workspace;

    private Monitor $monitor;

    private StatusPage $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->workspace = Workspace::create(['name' => 'Acme', 'slug' => 'acme', 'owner_id' => $this->user->id, 'billing_currency' => 'BDT', 'timezone' => 'UTC']);
        $this->workspace->members()->attach($this->user->id, ['role' => 'owner']);
        $this->monitor = Monitor::factory()->up()->create(['workspace_id' => $this->workspace->id, 'name' => 'Checkout', 'url' => 'https://shop.test/']);
        $this->page = StatusPage::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Acme Status', 'slug' => 'acme-status']);
        $this->page->monitors()->attach($this->monitor);
    }

    public function test_open_announcements_show_on_top_and_resolved_ones_in_the_history(): void
    {
        StatusPageAnnouncement::factory()->for($this->page)->create(['title' => 'Database upgrade tonight']);
        StatusPageAnnouncement::factory()->for($this->page)->resolved()->create(['title' => 'Payment provider delay']);

        $this->get('/status/acme-status')
            ->assertOk()
            ->assertSeeInOrder(['Database upgrade tonight', 'Checkout', 'Past 14 days', 'Payment provider delay', 'resolved']);
    }

    public function test_visitors_subscribe_with_email_confirmation_and_can_unsubscribe(): void
    {
        Mail::fake();

        $this->from('/status/acme-status')
            ->post('/status/acme-status/subscribe', ['email' => 'Visitor@Example.com'])
            ->assertRedirect('/status/acme-status')
            ->assertSessionHas('status_page_message');

        $subscriber = StatusPageSubscriber::sole();
        $this->assertSame('visitor@example.com', $subscriber->email);
        $this->assertNull($subscriber->confirmed_at);
        Mail::assertQueued(StatusPageNoticeMail::class, fn (StatusPageNoticeMail $mail): bool => $mail->hasTo('visitor@example.com') && $mail->heading === 'Confirm your subscription');

        $this->get(route('status-pages.subscription.confirm', ['token' => $subscriber->token]))->assertRedirect($this->page->publicUrl());
        $this->assertNotNull($subscriber->refresh()->confirmed_at);

        $this->get($subscriber->unsubscribeUrl())->assertRedirect($this->page->publicUrl());
        $this->assertSame(0, StatusPageSubscriber::count());
    }

    public function test_subscribing_again_with_a_confirmed_address_sends_nothing_and_reveals_nothing(): void
    {
        Mail::fake();
        StatusPageSubscriber::factory()->for($this->page)->confirmed()->create(['email' => 'known@example.com']);

        $this->from('/status/acme-status')
            ->post('/status/acme-status/subscribe', ['email' => 'known@example.com'])
            ->assertSessionHas('status_page_message', 'Check your inbox: we sent a link to confirm your subscription.');

        Mail::assertNothingQueued();
    }

    public function test_subscriptions_can_be_turned_off(): void
    {
        $this->page->update(['allow_subscriptions' => false]);

        $this->get('/status/acme-status')->assertOk()->assertDontSee('Get updates by email');
        $this->post('/status/acme-status/subscribe', ['email' => 'x@example.com'])->assertNotFound();
    }

    public function test_confirmed_subscribers_hear_about_an_outage_and_the_recovery(): void
    {
        Mail::fake();
        StatusPageSubscriber::factory()->for($this->page)->confirmed()->create(['email' => 'fan@example.com']);
        StatusPageSubscriber::factory()->for($this->page)->create(['email' => 'unconfirmed@example.com']);
        Http::fake(['https://shop.test/' => Http::sequence()->push('down', 503)->push('down', 503)->push('ok', 200)]);

        app(MonitorCheckRunner::class)->run($this->monitor);
        app(MonitorCheckRunner::class)->run($this->monitor->refresh());

        Mail::assertQueued(StatusPageNoticeMail::class, 2);
        Mail::assertQueued(StatusPageNoticeMail::class, fn (StatusPageNoticeMail $mail): bool => $mail->hasTo('fan@example.com') && $mail->heading === 'Checkout is down');
        Mail::assertQueued(StatusPageNoticeMail::class, fn (StatusPageNoticeMail $mail): bool => $mail->heading === 'Checkout is back up' && $mail->unsubscribeUrl !== null);
        Mail::assertNotQueued(StatusPageNoticeMail::class, fn (StatusPageNoticeMail $mail): bool => $mail->hasTo('unconfirmed@example.com'));
    }

    public function test_posting_an_announcement_emails_confirmed_subscribers(): void
    {
        Mail::fake();
        StatusPageSubscriber::factory()->for($this->page)->confirmed()->create(['email' => 'fan@example.com']);
        $this->actingAs($this->user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($this->workspace);
        Filament::bootCurrentPanel();

        Livewire::test(AnnouncementsRelationManager::class, ['ownerRecord' => $this->page, 'pageClass' => EditStatusPage::class])
            ->callTableAction('create', data: ['type' => 'maintenance', 'title' => 'Upgrade at 23:00', 'body' => 'Up to 15 minutes.', 'notify_subscribers' => true])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Upgrade at 23:00', StatusPageAnnouncement::sole()->title);
        Mail::assertQueued(StatusPageNoticeMail::class, fn (StatusPageNoticeMail $mail): bool => $mail->hasTo('fan@example.com') && $mail->heading === 'Upgrade at 23:00');
    }

    public function test_the_page_is_served_on_its_custom_domain_and_nothing_else_is(): void
    {
        $this->page->update(['custom_domain' => 'https://Status.Client.test/']);
        $this->assertSame('status.client.test', $this->page->refresh()->custom_domain);
        $this->assertSame('https://status.client.test', $this->page->publicUrl());

        $this->get('http://status.client.test/')
            ->assertOk()
            ->assertSee('Acme Status')
            ->assertSee('action="http://status.client.test/subscribe"', false);

        $this->get('http://status.client.test/app/login')->assertNotFound();
        $this->get('http://status.client.test/status/acme-status/anything')->assertNotFound();

        $this->get(config('app.url').'/status-domain-check?domain=status.client.test')->assertOk();
        $this->get(config('app.url').'/status-domain-check?domain=evil.test')->assertNotFound();
    }

    public function test_an_unpublished_page_is_not_served_on_its_custom_domain(): void
    {
        $this->page->update(['custom_domain' => 'status.client.test', 'is_published' => false]);

        $this->get(config('app.url').'/status-domain-check?domain=status.client.test')->assertNotFound();
        $this->get('http://status.client.test/')->assertStatus(200)->assertDontSee('Acme Status');
    }
}
