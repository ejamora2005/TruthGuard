<?php

namespace App\Services\Detections;

/** Cooperative stop: an in-flight request may finish, but no later work may start. */
class AnalysisStopped extends \RuntimeException {}
