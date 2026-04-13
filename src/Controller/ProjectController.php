<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\ProjectVersion;
use App\Entity\User;
use App\Form\ProjectType;
use App\Project\ProjectDashboardBuilder;
use App\Project\ProjectDuplicator;
use App\Project\ProjectShareLinkGenerator;
use App\Project\ProjectVersioning;
use App\Repository\ChatMessageRepository;
use App\Repository\ProjectGenerationMetricRepository;
use App\Repository\MediaAssetRepository;
use App\Repository\ProjectRepository;
use App\Repository\ProjectVersionRepository;
use App\Repository\SlideRepository;
use App\Theme\ThemeEngine;
use App\Theme\ThemePresetLoader;
use App\Theme\ThemeTokenValidator;
use App\Repository\ProjectGenerationMetricRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/projects')]
final class ProjectController extends AbstractController
{
    private const DEFAULT_SCOPE = ProjectRepository::SCOPE_ACTIVE;
    private const HISTORY_PER_PAGE = 5;
    private const DASHBOARD_PER_PAGE = 6;
    private const CHAT_MESSAGES_PER_PAGE = 10;

    #[Route('', name: 'app_project_index', methods: ['GET'])]
    #[Route('/dashboard', name: 'app_project_dashboard', methods: ['GET'])]
    public function dashboard(Request $request, ProjectRepository $projectRepository, ProjectDashboardBuilder $projectDashboardBuilder): Response
    {
        $scope = $this->normalizeScope((string) $request->query->get('scope', self::DEFAULT_SCOPE));
        $search = trim((string) $request->query->get('search', ''));
        $sort = $this->normalizeSort((string) $request->query->get('sort', ProjectRepository::SORT_UPDATED));
        $direction = $this->normalizeDirection((string) $request->query->get('direction', ProjectRepository::DIRECTION_DESC));
        $page = max(1, $request->query->getInt('page', 1));
        $dashboardPage = $projectRepository->findDashboardPage(
            $this->requireUser(),
            $scope,
            $search,
            $sort,
            $direction,
            $page,
            self::DASHBOARD_PER_PAGE,
        );
        $dashboardData = $projectDashboardBuilder->build($dashboardPage['projects']);

        return $this->render('project/index.html.twig', [
            'dashboardEntries' => $dashboardData['entries'],
            'summary' => $dashboardData['summary'],
            'currentScope' => $scope,
            'currentSearch' => $search,
            'currentSort' => $sort,
            'currentDirection' => $direction,
            'currentPage' => $dashboardPage['page'],
            'totalPages' => $dashboardPage['totalPages'],
            'hasPreviousPage' => $dashboardPage['page'] > 1,
            'hasNextPage' => $dashboardPage['page'] < $dashboardPage['totalPages'],
            'totalProjects' => $dashboardPage['total'],
            'sortOptions' => [
                ProjectRepository::SORT_UPDATED => 'project.index.sort.updated',
                ProjectRepository::SORT_NAME => 'project.index.sort.name',
                ProjectRepository::SORT_STATUS => 'project.index.sort.status',
            ],
            'directionOptions' => [
                ProjectRepository::DIRECTION_DESC => 'project.index.direction.desc',
                ProjectRepository::DIRECTION_ASC => 'project.index.direction.asc',
            ],
        ]);
    }

    #[Route('/new', name: 'app_project_new', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $entityManager, ProjectVersioning $projectVersioning): Response
    {
        $project = (new Project())->setUser($this->requireUser());
        $form = $this->createForm(ProjectType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($project);
            $entityManager->flush();
            $projectVersioning->captureSnapshot($project);

            $this->addFlash('success', 'project.flash.created');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
        }

        return $this->renderFormWithStatus('project/new.html.twig', $form, [
            'project' => $project,
        ]);
    }

    #[Route('/{id}', name: 'app_project_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, ProjectRepository $projectRepository, ChatMessageRepository $chatMessageRepository, MediaAssetRepository $mediaAssetRepository, SlideRepository $slideRepository): Response
    {
        $project = $this->findOwnedProjectOr404($id, $projectRepository);
        $costMap = $projectGenerationMetricRepository->sumEstimatedCostCentsByProjects([$project]);
        $totalAiCostUsd = round(($costMap[$project->getId() ?? 0] ?? 0) / 100, 4);

        $projectId = (int) $project->getId();
        $aiTotals  = $generationMetricRepository->sumEstimatedCostCentsByProjects([$project]);
        $aiCostUsd = round(((int) ($aiTotals[$projectId] ?? 0)) / 100, 4);

        return $this->render('project/show.html.twig', [
            'project'     => $project,
            'chatHistory' => $chatMessageRepository->paginateProjectConversation($project, 1, self::CHAT_MESSAGES_PER_PAGE),
            'mediaAssets' => $mediaAssetRepository->findByProject($project),
            'slides'      => $slideRepository->findByProjectOrdered($project),
        ]);
    }

    /**
     * HRM-F35 / T283–T290 — Two-panel editor layout (chat left 40 % + preview right 60 %).
     *
     * Dedicated full-viewport page: the layout uses CSS Grid with height:100vh and
     * internal scroll on each panel so the browser never shows a page-level scrollbar.
     */
    #[Route('/{id}/editor', name: 'app_project_editor', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function editor(int $id, ProjectRepository $projectRepository, ChatMessageRepository $chatMessageRepository): Response
    {
        $project = $this->findOwnedProjectOr404($id, $projectRepository);

        return $this->render('project/editor.html.twig', [
            'project' => $project,
            'chatHistory' => $chatMessageRepository->paginateProjectConversation($project, 1, self::CHAT_MESSAGES_PER_PAGE),
            'totalAiCostUsd' => $totalAiCostUsd,
            'aiCostUsd' => $aiCostUsd,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_project_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request, ProjectRepository $projectRepository, EntityManagerInterface $entityManager, ProjectVersioning $projectVersioning): Response
    {
        $project = $this->findOwnedEditableProjectOr404($id, $projectRepository);
        $form = $this->createForm(ProjectType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $projectVersioning->captureSnapshot($project);
            $this->addFlash('success', 'project.flash.updated');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
        }

        return $this->renderFormWithStatus('project/edit.html.twig', $form, [
            'project' => $project,
        ]);
    }

    #[Route('/{id}/duplicate', name: 'app_project_duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function duplicate(int $id, Request $request, ProjectRepository $projectRepository, ProjectDuplicator $projectDuplicator, ProjectVersioning $projectVersioning, EntityManagerInterface $entityManager): Response
    {
        $sourceProject = $this->findOwnedProjectOr404($id, $projectRepository);
        $this->denyInvalidToken('duplicate_project_'.$sourceProject->getId(), (string) $request->request->get('_token'));

        $duplicatedProject = $projectDuplicator->duplicate($sourceProject, $this->requireUser());
        $entityManager->persist($duplicatedProject);
        $entityManager->flush();
        $projectVersioning->captureSnapshot($duplicatedProject);
        $this->addFlash('success', 'project.flash.duplicated');

        return $this->redirectToRoute('app_project_show', ['id' => $duplicatedProject->getId()]);
    }

    #[Route('/{id}/archive', name: 'app_project_archive', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function archive(int $id, Request $request, ProjectRepository $projectRepository, EntityManagerInterface $entityManager, ProjectVersioning $projectVersioning): Response
    {
        $project = $this->findOwnedEditableProjectOr404($id, $projectRepository);
        $this->denyInvalidToken('archive_project_'.$project->getId(), (string) $request->request->get('_token'));

        $project->archive();
        $entityManager->flush();
        $projectVersioning->captureSnapshot($project);
        $this->addFlash('success', 'project.flash.archived');

        return $this->redirectToRoute('app_project_index', ['scope' => ProjectRepository::SCOPE_ARCHIVED]);
    }

    #[Route('/{id}/restore', name: 'app_project_restore', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function restore(int $id, Request $request, ProjectRepository $projectRepository, EntityManagerInterface $entityManager, ProjectVersioning $projectVersioning): Response
    {
        $project = $this->findOwnedProjectOr404($id, $projectRepository);
        $this->denyInvalidToken('restore_project_'.$project->getId(), (string) $request->request->get('_token'));

        if (!$project->isArchived()) {
            throw $this->createNotFoundException();
        }

        $project->restore();
        $entityManager->flush();
        $projectVersioning->captureSnapshot($project);
        $this->addFlash('success', 'project.flash.restored');

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
    }

    #[Route('/{id}/versions', name: 'app_project_versions', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function versions(int $id, Request $request, ProjectRepository $projectRepository, ProjectVersionRepository $projectVersionRepository, ProjectVersioning $projectVersioning): Response
    {
        $project = $this->findOwnedProjectOr404($id, $projectRepository);
        $page = max(1, $request->query->getInt('page', 1));
        $totalVersions = $projectVersionRepository->countByProject($project);
        $totalPages = max(1, (int) ceil($totalVersions / self::HISTORY_PER_PAGE));
        $page = min($page, $totalPages);

        $versions = $projectVersionRepository->findPaginatedByProject($project, $page, self::HISTORY_PER_PAGE);
        $versionEntries = array_map(fn (ProjectVersion $version): array => [
            'version' => $version,
            'diff' => $projectVersioning->describeDiffFromCurrent($version, $project),
        ], $versions);

        return $this->render('project/history.html.twig', [
            'project' => $project,
            'versionEntries' => $versionEntries,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'hasPreviousPage' => $page > 1,
            'hasNextPage' => $page < $totalPages,
            'totalVersions' => $totalVersions,
        ]);
    }

    #[Route('/{projectId}/versions/{versionId}/restore', name: 'app_project_version_restore', methods: ['POST'], requirements: ['projectId' => '\d+', 'versionId' => '\d+'])]
    public function restoreVersion(int $projectId, int $versionId, Request $request, ProjectRepository $projectRepository, ProjectVersionRepository $projectVersionRepository, ProjectVersioning $projectVersioning): Response
    {
        $project = $this->findOwnedProjectOr404($projectId, $projectRepository);
        $version = $projectVersionRepository->findOwnedVersion($project, $versionId);
        if (!$version instanceof ProjectVersion) {
            throw $this->createNotFoundException();
        }

        $this->denyInvalidToken('restore_project_version_'.$version->getId(), (string) $request->request->get('_token'));
        $projectVersioning->restoreVersion($project, $version);
        $this->addFlash('success', 'project.history.flash.restored');

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
    }

    #[Route('/{id}/delete', name: 'app_project_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request, ProjectRepository $projectRepository, EntityManagerInterface $entityManager): Response
    {
        $project = $this->findOwnedProjectOr404($id, $projectRepository);
        $this->denyInvalidToken('delete_project_'.$project->getId(), (string) $request->request->get('_token'));

        $project->markDeleted();
        $entityManager->flush();
        $this->addFlash('success', 'project.flash.deleted');

        return $this->redirectToRoute('app_project_index');
    }

    #[Route('/{id}/share/generate', name: 'app_project_share_generate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function generateShareLink(int $id, Request $request, ProjectRepository $projectRepository, ProjectShareLinkGenerator $projectShareLinkGenerator, EntityManagerInterface $entityManager): Response
    {
        $project = $this->findOwnedProjectOr404($id, $projectRepository);
        $this->denyInvalidToken('share_project_'.$project->getId(), (string) $request->request->get('_token'));

        $shareLink = $projectShareLinkGenerator->generate();
        $project->activateShare($shareLink['token'], $shareLink['expiresAt']);
        $entityManager->flush();
        $this->addFlash('success', 'project.share.flash.generated');

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
    }

    #[Route('/{id}/share/revoke', name: 'app_project_share_revoke', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function revokeShareLink(int $id, Request $request, ProjectRepository $projectRepository, EntityManagerInterface $entityManager): Response
    {
        $project = $this->findOwnedProjectOr404($id, $projectRepository);
        $this->denyInvalidToken('revoke_share_project_'.$project->getId(), (string) $request->request->get('_token'));

        $project->revokeShare();
        $entityManager->flush();
        $this->addFlash('success', 'project.share.flash.revoked');

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
    }

    /**
     * T182 — Apply a built-in theme preset to a project.
     *
     * Updates Project::themeConfigJson with the preset's token map and invalidates the
     * renderHash of every associated Slide entity so the next render picks up the new CSS.
     */
    #[Route('/{id}/theme/preset', name: 'app_project_theme_preset', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function applyThemePreset(
        int $id,
        Request $request,
        ProjectRepository $projectRepository,
        SlideRepository $slideRepository,
        ThemeEngine $themeEngine,
        ThemePresetLoader $themePresetLoader,
        ProjectVersioning $projectVersioning,
        EntityManagerInterface $entityManager,
    ): Response {
        $project = $this->findOwnedEditableProjectOr404($id, $projectRepository);
        $this->denyInvalidToken('theme_preset_'.$project->getId(), (string) $request->request->get('_token'));

        $presetName = trim((string) $request->request->get('preset', ''));

        if (!in_array($presetName, ThemeEngine::presetNames(), true)) {
            $this->addFlash('error', 'project.theme.flash.invalid_preset');

            return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
        }

        $preset = $themePresetLoader->load($presetName);
        $slides = $slideRepository->findByProjectOrdered($project);
        $themeEngine->applyPresetToProject($preset, $project, $slides);
        $entityManager->flush();
        $projectVersioning->captureSnapshot($project);

        $this->addFlash('success', 'project.theme.flash.applied');

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
    }

    /**
     * T190 / T191 — Patch the project's theme token overrides (AJAX).
     *
     * Accepts a flat token map sent as form data (`tokens[--hm-bg]=...` etc.) and merges
     * it onto the existing themeConfigJson. Only allow-listed --hm-* tokens pass validation.
     * All associated slide renderHashes are invalidated (T191) so the next render picks up
     * the new values.
     *
     * Returns JSON `{success: true, cssBlock: "..."}` for XHR callers, or redirects for
     * non-XHR callers.
     */
    #[Route('/{id}/theme/tokens', name: 'app_project_theme_tokens', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function patchThemeTokens(
        int $id,
        Request $request,
        ProjectRepository $projectRepository,
        SlideRepository $slideRepository,
        ThemeEngine $themeEngine,
        ThemeTokenValidator $themeTokenValidator,
        ProjectVersioning $projectVersioning,
        EntityManagerInterface $entityManager,
    ): Response {
        $project = $this->findOwnedEditableProjectOr404($id, $projectRepository);
        $this->denyInvalidToken('theme_tokens_'.$project->getId(), (string) $request->request->get('_token'));

        $rawTokens = $request->request->all('tokens');
        $slides = $slideRepository->findByProjectOrdered($project);
        $themeEngine->mergeTokenOverrides($rawTokens, $project, $slides, $themeTokenValidator);
        $entityManager->flush();
        $projectVersioning->captureSnapshot($project);

        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'success' => true,
                'cssBlock' => $themeEngine->toCssBlock($project->getEffectiveThemeConfigJson()),
            ]);
        }

        $this->addFlash('success', 'project.theme.tokens.flash.saved');

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
    }

    /**
     * T205 — Reset the project's user overrides back to the preset base, clearing all
     * manual token customisations from the theme customization drawer.
     *
     * Clears themeOverridesJson, increments themeVersion, and invalidates all slide render
     * caches so the next render picks up the clean preset tokens.
     */
    #[Route('/{id}/theme/reset', name: 'app_project_theme_reset', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resetThemeOverrides(
        int $id,
        Request $request,
        ProjectRepository $projectRepository,
        SlideRepository $slideRepository,
        ThemeEngine $themeEngine,
        ProjectVersioning $projectVersioning,
        EntityManagerInterface $entityManager,
    ): Response {
        $project = $this->findOwnedEditableProjectOr404($id, $projectRepository);
        $this->denyInvalidToken('theme_reset_'.$project->getId(), (string) $request->request->get('_token'));

        $slides = $slideRepository->findByProjectOrdered($project);
        $themeEngine->resetOverrides($project, $slides);
        $entityManager->flush();
        $projectVersioning->captureSnapshot($project);

        $this->addFlash('success', 'project.theme.reset.flash.done');

        return $this->redirectToRoute('app_project_show', ['id' => $project->getId()]);
    }

    /**
     * T318 — Persist the chosen AI provider and model via AJAX.
     *
     * Accepts form-encoded body: provider, model, _token.
     * Returns JSON `{success: true, provider, model}`.
     */
    #[Route('/{id}/ai/settings', name: 'app_project_ai_settings', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function saveAiSettings(
        int $id,
        Request $request,
        ProjectRepository $projectRepository,
        EntityManagerInterface $entityManager,
    ): JsonResponse {
        $project = $this->findOwnedEditableProjectOr404($id, $projectRepository);
        $this->denyInvalidToken('ai_settings_'.$project->getId(), (string) $request->request->get('_token'));

        $provider = trim((string) $request->request->get('provider', $project->getProvider()));
        $model    = trim((string) $request->request->get('model', $project->getModel()));

        if (!in_array($provider, Project::providerValues(), true)) {
            return $this->json(['error' => 'project.provider.invalid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!in_array($model, Project::modelValues(), true)) {
            return $this->json(['error' => 'project.model.invalid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $project->setProvider($provider)->setModel($model);
        $entityManager->flush();

        return $this->json([
            'success'  => true,
            'provider' => $provider,
            'model'    => $model,
        ]);
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function findOwnedProjectOr404(int $id, ProjectRepository $projectRepository): Project
    {
        $project = $projectRepository->findOwnedProject($id, $this->requireUser());
        if (!$project instanceof Project) {
            throw $this->createNotFoundException();
        }

        return $project;
    }

    private function findOwnedEditableProjectOr404(int $id, ProjectRepository $projectRepository): Project
    {
        $project = $projectRepository->findOwnedEditableProject($id, $this->requireUser());
        if (!$project instanceof Project) {
            throw $this->createNotFoundException();
        }

        return $project;
    }

    private function denyInvalidToken(string $tokenId, string $token): void
    {
        if (!$this->isCsrfTokenValid($tokenId, $token)) {
            throw $this->createAccessDeniedException();
        }
    }

    private function normalizeScope(string $scope): string
    {
        return in_array($scope, [
            ProjectRepository::SCOPE_ACTIVE,
            ProjectRepository::SCOPE_ARCHIVED,
            ProjectRepository::SCOPE_ALL,
        ], true) ? $scope : self::DEFAULT_SCOPE;
    }

    private function normalizeSort(string $sort): string
    {
        return in_array($sort, [
            ProjectRepository::SORT_UPDATED,
            ProjectRepository::SORT_NAME,
            ProjectRepository::SORT_STATUS,
        ], true) ? $sort : ProjectRepository::SORT_UPDATED;
    }

    private function normalizeDirection(string $direction): string
    {
        return in_array($direction, [
            ProjectRepository::DIRECTION_ASC,
            ProjectRepository::DIRECTION_DESC,
        ], true) ? $direction : ProjectRepository::DIRECTION_DESC;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function renderFormWithStatus(string $template, mixed $form, array $parameters = []): Response
    {
        $statusCode = $form->isSubmitted() && !$form->isValid()
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render($template, [
            ...$parameters,
            'projectForm' => $form,
            'modelsByProvider' => json_encode(Project::modelsByProvider(), JSON_THROW_ON_ERROR),
        ], new Response(status: $statusCode));
    }
}
