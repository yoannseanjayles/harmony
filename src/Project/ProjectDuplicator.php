<?php

namespace App\Project;

use App\Entity\Project;
use App\Entity\User;

final class ProjectDuplicator
{
    public function duplicate(Project $source, User $owner): Project
    {
        return (new Project())
            ->setTitle($this->duplicateTitle($source->getTitle()))
            ->setProvider($source->getProvider())
            ->setModel($source->getModel())
            ->setStatus($source->getStatus())
            ->setSlides($source->getSlides())
            ->setThemeConfig($source->getThemeConfig())
            ->setMetadata($source->getMetadata())
            ->setMediaRefs($source->getMediaRefs())
            ->setUser($owner);
    }

    private function duplicateTitle(string $title): string
    {
        $prefix = 'Copie de ';
        $available = 160 - mb_strlen($prefix);

        return $prefix.mb_substr(trim($title), 0, max($available, 1));
    }
}
