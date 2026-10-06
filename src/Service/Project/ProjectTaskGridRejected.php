<?php

declare(strict_types=1);

namespace App\Service\Project;

/** Thrown inside ProjectTaskGrid::save()'s transaction to roll back every row when one of them is refused. */
final class ProjectTaskGridRejected extends \RuntimeException
{
}
