<?php

use App\Enums\TranscodeStatus;

describe('canTransitionTo', function () {
    it('allows pending to transition to queued', function () {
        expect(TranscodeStatus::Pending->canTransitionTo(TranscodeStatus::Queued))->toBeTrue();
    });

    it('allows queued to transition to processing', function () {
        expect(TranscodeStatus::Queued->canTransitionTo(TranscodeStatus::Processing))->toBeTrue();
    });

    it('allows processing to transition to completed', function () {
        expect(TranscodeStatus::Processing->canTransitionTo(TranscodeStatus::Completed))->toBeTrue();
    });

    it('allows processing to transition to failed', function () {
        expect(TranscodeStatus::Processing->canTransitionTo(TranscodeStatus::Failed))->toBeTrue();
    });

    it('allows processing to transition back to queued for retries', function () {
        expect(TranscodeStatus::Processing->canTransitionTo(TranscodeStatus::Queued))->toBeTrue();
    });

    it('rejects pending to processing', function () {
        expect(TranscodeStatus::Pending->canTransitionTo(TranscodeStatus::Processing))->toBeFalse();
    });

    it('rejects pending to completed', function () {
        expect(TranscodeStatus::Pending->canTransitionTo(TranscodeStatus::Completed))->toBeFalse();
    });

    it('rejects queued to completed', function () {
        expect(TranscodeStatus::Queued->canTransitionTo(TranscodeStatus::Completed))->toBeFalse();
    });

    it('rejects queued to failed', function () {
        expect(TranscodeStatus::Queued->canTransitionTo(TranscodeStatus::Failed))->toBeFalse();
    });

    it('rejects any transition from completed', function () {
        foreach (TranscodeStatus::cases() as $next) {
            expect(TranscodeStatus::Completed->canTransitionTo($next))->toBeFalse();
        }
    });

    it('rejects any transition from failed', function () {
        foreach (TranscodeStatus::cases() as $next) {
            expect(TranscodeStatus::Failed->canTransitionTo($next))->toBeFalse();
        }
    });
});

describe('isTerminal', function () {
    it('marks completed as terminal', function () {
        expect(TranscodeStatus::Completed->isTerminal())->toBeTrue();
    });

    it('marks failed as terminal', function () {
        expect(TranscodeStatus::Failed->isTerminal())->toBeTrue();
    });

    it('marks pending as non-terminal', function () {
        expect(TranscodeStatus::Pending->isTerminal())->toBeFalse();
    });

    it('marks queued as non-terminal', function () {
        expect(TranscodeStatus::Queued->isTerminal())->toBeFalse();
    });

    it('marks processing as non-terminal', function () {
        expect(TranscodeStatus::Processing->isTerminal())->toBeFalse();
    });
});
