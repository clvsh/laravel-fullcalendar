<?php

namespace Tests;

use DateTime;
use Illuminate\Contracts\View\View;
use Illuminate\View\Factory;
use MaddHatter\LaravelFullcalendar\Calendar;
use MaddHatter\LaravelFullcalendar\Event;
use MaddHatter\LaravelFullcalendar\EventCollection;
use MaddHatter\LaravelFullcalendar\IdentifiableEvent;
use MaddHatter\LaravelFullcalendar\SimpleEvent;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

class CalendarTest extends TestCase
{
    /**
     * @var Factory&MockInterface
     */
    private $view;

    protected function setUp(): void
    {
        parent::setUp();

        $this->view = Mockery::mock(Factory::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function makeCalendar(): Calendar
    {
        return new Calendar($this->view, new EventCollection());
    }

    public function test_event_factory_builds_identifiable_event_from_date_strings(): void
    {
        $event = Calendar::event("Valentine's Day", true, '2015-02-14', '2015-02-15', 7, ['url' => 'http://full-calendar.io']);

        $this->assertInstanceOf(SimpleEvent::class, $event);
        $this->assertInstanceOf(IdentifiableEvent::class, $event);
        $this->assertSame("Valentine's Day", $event->getTitle());
        $this->assertTrue($event->isAllDay());
        $this->assertSame(7, $event->getId());
        $this->assertInstanceOf(DateTime::class, $event->getStart());
        $this->assertInstanceOf(DateTime::class, $event->getEnd());
        $this->assertSame('2015-02-14', $event->getStart()->format('Y-m-d'));
        $this->assertSame('2015-02-15', $event->getEnd()->format('Y-m-d'));
        $this->assertSame(['url' => 'http://full-calendar.io'], $event->getEventOptions());
    }

    public function test_event_factory_keeps_datetime_instances_and_defaults(): void
    {
        $start = new DateTime('2015-02-11T08:00:00');
        $end   = new DateTime('2015-02-12T08:00:00');

        $event = Calendar::event('Event One', false, $start, $end);

        $this->assertSame($start, $event->getStart());
        $this->assertSame($end, $event->getEnd());
        $this->assertFalse($event->isAllDay());
        $this->assertNull($event->getId());
        $this->assertSame([], $event->getEventOptions());
    }

    public function test_event_collection_serializes_id_options_and_custom_attributes(): void
    {
        $start = new DateTime('2015-02-11T08:00:00+00:00');
        $end   = new DateTime('2015-02-12T08:00:00+00:00');
        $event = new SimpleEvent('Event One', false, $start, $end, 'stringEventId', [
            'url'   => 'http://full-calendar.io',
            'color' => 'blue',
        ]);

        $collection = new EventCollection();
        $collection->push($event, ['color' => '#800']);

        $expected = [
            [
                'id'     => 'stringEventId',
                'title'  => 'Event One',
                'allDay' => false,
                'start'  => '2015-02-11T08:00:00+00:00',
                'end'    => '2015-02-12T08:00:00+00:00',
                'url'    => 'http://full-calendar.io',
                'color'  => '#800',
            ],
        ];

        $this->assertSame($expected, $collection->toArray());
        $this->assertSame(json_encode($expected), $collection->toJson());
    }

    public function test_event_collection_handles_plain_events_without_id_or_options(): void
    {
        $event = new class implements Event {
            public function getTitle()
            {
                return 'Plain';
            }

            public function isAllDay()
            {
                return true;
            }

            public function getStart()
            {
                return new DateTime('2015-02-14T00:00:00+00:00');
            }

            public function getEnd()
            {
                return new DateTime('2015-02-14T00:00:00+00:00');
            }
        };

        $collection = new EventCollection();
        $collection->push($event);

        $this->assertSame([
            [
                'id'     => null,
                'title'  => 'Plain',
                'allDay' => true,
                'start'  => '2015-02-14T00:00:00+00:00',
                'end'    => '2015-02-14T00:00:00+00:00',
            ],
        ], $collection->toArray());
    }

    public function test_user_options_are_merged_over_defaults(): void
    {
        $calendar = $this->makeCalendar();

        $defaults = $calendar->getOptions();
        $this->assertSame([
            'left'   => 'prev,next today',
            'center' => 'title',
            'right'  => 'month,agendaWeek,agendaDay',
        ], $defaults['header']);
        $this->assertTrue($defaults['eventLimit']);

        $result = $calendar->setOptions(['firstDay' => 1, 'eventLimit' => false]);
        $this->assertSame($calendar, $result);

        $options = $calendar->getOptions();
        $this->assertSame($defaults['header'], $options['header']);
        $this->assertFalse($options['eventLimit']);
        $this->assertSame(1, $options['firstDay']);
    }

    public function test_options_json_includes_added_events(): void
    {
        $calendar = $this->makeCalendar();
        $calendar
            ->addEvent(Calendar::event('One', false, new DateTime('2015-02-11T08:00:00+00:00'), new DateTime('2015-02-12T08:00:00+00:00'), 1))
            ->addEvents([
                Calendar::event('Two', true, new DateTime('2015-02-14T00:00:00+00:00'), new DateTime('2015-02-14T00:00:00+00:00'), 2),
            ], ['color' => '#800']);

        $decoded = json_decode($calendar->getOptionsJson(), true);

        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['eventLimit']);
        $this->assertCount(2, $decoded['events']);
        $this->assertSame(1, $decoded['events'][0]['id']);
        $this->assertSame('One', $decoded['events'][0]['title']);
        $this->assertArrayNotHasKey('color', $decoded['events'][0]);
        $this->assertSame(2, $decoded['events'][1]['id']);
        $this->assertSame('#800', $decoded['events'][1]['color']);
    }

    public function test_events_option_overrides_event_list(): void
    {
        $calendar = $this->makeCalendar();
        $calendar
            ->addEvent(Calendar::event('Ignored', false, '2015-02-11', '2015-02-12'))
            ->setOptions(['events' => '/calendar/feed']);

        $decoded = json_decode($calendar->getOptionsJson(), true);

        $this->assertSame('/calendar/feed', $decoded['events']);
    }

    public function test_callbacks_are_injected_as_raw_javascript(): void
    {
        $callback = 'function() {alert("Callbacks!");}';
        $calendar = $this->makeCalendar();

        $result = $calendar->setCallbacks(['viewRender' => $callback]);
        $this->assertSame($calendar, $result);
        $this->assertSame(['viewRender' => $callback], $calendar->getCallbacks());

        $json = $calendar->getOptionsJson();

        $this->assertStringContainsString('"viewRender":' . $callback, $json);
        $this->assertStringNotContainsString(md5($callback), $json);
        $this->assertStringContainsString('"eventLimit":true', $json);
        $this->assertStringContainsString('"events":[]', $json);
    }

    public function test_generated_id_is_stable_and_used_in_div_markup(): void
    {
        $calendar = $this->makeCalendar();

        $id = $calendar->getId();

        $this->assertSame(8, strlen($id));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{8}$/', $id);
        $this->assertSame($id, $calendar->getId());
        $this->assertSame('<div id="calendar-' . $id . '"></div>', $calendar->calendar());

        $this->assertNotSame($id, $this->makeCalendar()->getId());
    }

    public function test_custom_id_is_used_in_div_markup(): void
    {
        $calendar = $this->makeCalendar();

        $this->assertSame($calendar, $calendar->setId('custom-id'));
        $this->assertSame('custom-id', $calendar->getId());
        $this->assertSame('<div id="calendar-custom-id"></div>', $calendar->calendar());
    }

    public function test_script_renders_namespaced_view_with_id_and_options_json(): void
    {
        $calendar = $this->makeCalendar()
            ->setId('custom-id')
            ->setOptions(['firstDay' => 1])
            ->setCallbacks(['viewRender' => 'function() {}']);

        $rendered = Mockery::mock(View::class);

        $this->view
            ->shouldReceive('make')
            ->once()
            ->with('fullcalendar::script', [
                'id'      => 'custom-id',
                'options' => $calendar->getOptionsJson(),
            ])
            ->andReturn($rendered);

        $this->assertSame($rendered, $calendar->script());
    }
}
