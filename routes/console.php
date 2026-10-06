<?php
use Illuminate\Support\Facades\Schedule;
Schedule::command('billing:generate-invoices')->monthlyOn(1,'00:10')->withoutOverlapping();
Schedule::command('billing:fup-reset')->monthlyOn(10,'00:20')->withoutOverlapping();
Schedule::command('billing:isolate-overdue')->dailyAt('01:00')->withoutOverlapping();
Schedule::command('billing:fup-collect')->everyFiveMinutes()->withoutOverlapping(4);
Schedule::command('billing:check-routers')->everyTenMinutes()->withoutOverlapping(5);
Schedule::command('billing:reminders 7')->dailyAt('08:00')->withoutOverlapping();
Schedule::command('billing:reminders 3')->dailyAt('08:10')->withoutOverlapping();
Schedule::command('billing:reminders 1')->dailyAt('08:20')->withoutOverlapping();
Schedule::command('billing:reminders 0')->dailyAt('08:30')->withoutOverlapping();
Schedule::command('billing:reminders -1')->dailyAt('08:40')->withoutOverlapping();
Schedule::command('billing:reminders -3')->dailyAt('08:50')->withoutOverlapping();
Schedule::command('billing:reminders -7')->dailyAt('09:00')->withoutOverlapping();

