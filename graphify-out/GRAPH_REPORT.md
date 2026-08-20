# Graph Report - exin-deploy  (2026-08-18)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 1467 nodes · 3866 edges · 128 communities (80 shown, 48 thin omitted)
- Extraction: 100% EXTRACTED · 0% INFERRED · 0% AMBIGUOUS · INFERRED: 14 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `326a1ec8`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- TestCase
- WorkflowTemplate
- Illuminate\Database\Eloquent\Factories\Factory
- Illuminate\Support\Facades\Schema
- FortifyServiceProvider.php
- User
- ReleaseStep
- WellFormedLinkTest
- ReleaseEvent
- ReleaseStatus.php
- Project
- StartReleaseTest.php
- Illuminate\Database\Eloquent\Concerns\HasUuids
- Role
- CloseStepTest
- ProjectRoleAssignment
- Release
- FieldType
- ReleaseIndexScreenTest
- ReleaseStepPolicyTest
- Illuminate\Database\Eloquent\Relations\HasMany
- MyStepsScreenTest
- StartReleaseScreenTest
- Illuminate\Database\Eloquent\Relations\BelongsTo
- devDependencies
- CloseStepScreenTest
- ReleaseDetailScreenTest
- StartReleaseTest
- Illuminate\Database\Eloquent\Builder
- StepDefinition
- scripts
- ReleaseStepField
- StepDefinitionManagementTest
- composer.json
- ConfigurationPolicyTest
- ReleaseLogScreenTest
- DatabaseSeeder.php
- FieldDefinitionManagementTest
- RoleManagementTest
- .projectReadyToRelease
- DefaultRoleAssignment
- ReleaseSchemaConstraintsTest
- FieldDefinition
- SaveStepValuesTest
- ReleasePolicyTest
- ProjectPolicy
- FieldDefinitionFactory
- ReleaseEventFactory
- LoginTest
- SchemaConstraintsTest
- MyStepsQueryBudgetTest
- ReleaseDetailQueryBudgetTest
- RolePolicy
- UserPolicy
- require-dev
- ReleaseIndexQueryBudgetTest
- OrderedByPosition.php
- ReleaseStepPolicy
- require
- setup
- UserPolicyTest
- ReleasePolicy
- config
- ⚡fields.blade.php
- ⚡steps.blade.php
- ReleaseEventPolicyTest
- .projectWith
- ReleaseEventIsAppendOnly
- ReleaseEventPolicy
- roles/⚡index.blade.php
- templates/⚡index.blade.php
- CloseStepQueryBudgetTest
- ReleaseLogQueryBudgetTest
- psr-4
- logging.php
- members/⚡index.blade.php
- projects/⚡index.blade.php
- larapilot-refresh
- post-create-project-cmd
- extra
- console.php
- laravel-boost
- laravel-boost
- laravel-boost
- Controller.php
- releases/⚡index.blade.php
- ⚡start.blade.php
- ⚡step.blade.php

## God Nodes (most connected - your core abstractions)
1. `User` - 380 edges
2. `Project` - 151 edges
3. `ReleaseStep` - 149 edges
4. `Release` - 142 edges
5. `WorkflowTemplate` - 132 edges
6. `TestCase` - 120 edges
7. `Role` - 118 edges
8. `StepDefinition` - 90 edges
9. `ReleaseEvent` - 76 edges
10. `ProjectRoleAssignment` - 63 edges

## Surprising Connections (you probably didn't know these)
- `FieldDefinitionManagementTest` --references--> `WorkflowTemplate`  [EXTRACTED]
  tests/Feature/Configuration/FieldDefinitionManagementTest.php → app/Models/WorkflowTemplate.php
- `StepDefinitionManagementTest` --references--> `WorkflowTemplate`  [EXTRACTED]
  tests/Feature/Configuration/StepDefinitionManagementTest.php → app/Models/WorkflowTemplate.php
- `StartReleaseTest` --references--> `StartRelease`  [EXTRACTED]
  tests/Feature/Releases/StartReleaseTest.php → app/Actions/Releases/StartRelease.php
- `StartReleaseTest` --references--> `User`  [EXTRACTED]
  tests/Feature/Releases/StartReleaseTest.php → app/Models/User.php
- `FieldDefinitionManagementTest` --references--> `StepDefinition`  [EXTRACTED]
  tests/Feature/Configuration/FieldDefinitionManagementTest.php → app/Models/StepDefinition.php

## Import Cycles
- None detected.

## Communities (128 total, 48 thin omitted)

### Community 0 - "TestCase"
Cohesion: 0.05
Nodes (25): CreateProject, Illuminate\Auth\Notifications\ResetPassword, Illuminate\Database\Eloquent\Collection, Illuminate\Database\Eloquent\ModelNotFoundException, Illuminate\Database\Events\QueryExecuted, Illuminate\Database\QueryException, Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase (+17 more)

### Community 1 - "WorkflowTemplate"
Cohesion: 0.06
Nodes (9): SetDefaultWorkflowTemplate, InactiveTemplateCannotBeDefault, self, WorkflowTemplate, DefaultWorkflowTemplateTest, ProjectTemplateAssociationTest, TemplateUsabilityTest, WorkflowTemplateManagementTest (+1 more)

### Community 2 - "Illuminate\Database\Eloquent\Factories\Factory"
Cohesion: 0.06
Nodes (16): DefaultRoleAssignmentFactory, static, ProjectFactory, static, ReleaseFactory, static, ReleaseStepFactory, static (+8 more)

### Community 3 - "Illuminate\Support\Facades\Schema"
Cohesion: 0.06
Nodes (3): Illuminate\Database\Migrations\Migration, Illuminate\Database\Schema\Blueprint, Illuminate\Support\Facades\Schema

### Community 4 - "FortifyServiceProvider.php"
Cohesion: 0.06
Nodes (23): ResetUserPassword, UpdateUserPassword, UpdateUserProfileInformation, AppServiceProvider, FortifyServiceProvider, Illuminate\Cache\RateLimiting\Limit, Illuminate\Contracts\Auth\MustVerifyEmail, Illuminate\Contracts\Validation\Rule (+15 more)

### Community 5 - "User"
Cohesion: 0.07
Nodes (6): User, DefaultRoleAssignmentPolicy, WorkflowTemplatePolicy, Illuminate\Foundation\Auth\User, MemberManagementTest, UserTest

### Community 6 - "ReleaseStep"
Cohesion: 0.09
Nodes (4): self, self, ReleaseStep, ReleaseStepTest

### Community 7 - "WellFormedLinkTest"
Cohesion: 0.09
Nodes (10): AssignableUser, WellFormedLink, Closure, Illuminate\Contracts\Validation\ValidationRule, Illuminate\Support\Arr, Illuminate\Translation\PotentiallyTranslatedString, PHPUnit\Framework\Attributes\DataProvider, Symfony\Component\Finder\Finder (+2 more)

### Community 8 - "ReleaseEvent"
Cohesion: 0.11
Nodes (4): RecordUnauthorizedStepAttempt, ReleaseEvent, ReleaseEventAppendOnlyTest, ReleaseLogInstantTest

### Community 9 - "ReleaseStatus.php"
Cohesion: 0.14
Nodes (9): CloseStep, SaveStepValues, StepIsNotOpen, self, StepValuesAreInvalid, Carbon\CarbonInterface, Illuminate\Support\Facades\Validator, Illuminate\Support\MessageBag (+1 more)

### Community 10 - "Project"
Cohesion: 0.10
Nodes (5): self, Project, ProjectRoleAssignmentFactory, ProjectManagementTest, ProjectTest

### Community 11 - "StartReleaseTest.php"
Cohesion: 0.10
Nodes (12): StartRelease, InactiveProjectCannotStartRelease, InactiveResponsibleOnProject, self, self, ProjectWithoutUsableTemplate, self, RolesWithoutResponsible (+4 more)

### Community 12 - "Illuminate\Database\Eloquent\Concerns\HasUuids"
Cohesion: 0.33
Nodes (6): Illuminate\Database\Eloquent\Attributes\Fillable, Illuminate\Database\Eloquent\Attributes\Scope, Illuminate\Database\Eloquent\Concerns\HasUuids, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Database\Eloquent\Model, Illuminate\Database\Eloquent\Relations\HasOne

### Community 13 - "Role"
Cohesion: 0.12
Nodes (3): Role, DefaultAssignmentsTest, RoleTest

### Community 16 - "Release"
Cohesion: 0.16
Nodes (3): Release, DatabaseSeederTest, ReleaseTest

### Community 17 - "FieldType"
Cohesion: 0.20
Nodes (3): FieldType, FieldTypeTest, ReleaseStepFieldTest

### Community 20 - "Illuminate\Database\Eloquent\Relations\HasMany"
Cohesion: 0.06
Nodes (11): static, UserFactory, Illuminate\Database\Eloquent\Attributes\Hidden, Illuminate\Database\Eloquent\Relations\HasMany, Illuminate\Notifications\Notifiable, Illuminate\Support\Facades\Cache, Laravel\Fortify\Features, Laravel\Fortify\Fortify (+3 more)

### Community 24 - "devDependencies"
Cohesion: 0.11
Nodes (17): concurrently, laravel-vite-plugin, devDependencies, concurrently, laravel-vite-plugin, tailwindcss, @tailwindcss/vite, vite (+9 more)

### Community 29 - "StepDefinition"
Cohesion: 0.21
Nodes (3): StepDefinition, OrderedByPositionTest, StepDefinitionTest

### Community 30 - "scripts"
Cohesion: 0.12
Nodes (17): scripts, analyse, dev, lint, lint:check, post-autoload-dump, post-update-cmd, pre-package-uninstall (+9 more)

### Community 33 - "composer.json"
Cohesion: 0.13
Nodes (14): autoload-dev, psr-4, description, keywords, license, minimum-stability, name, prefer-stable (+6 more)

### Community 36 - "DatabaseSeeder.php"
Cohesion: 0.26
Nodes (3): DatabaseSeeder, Illuminate\Database\Console\Seeds\WithoutModelEvents, Illuminate\Database\Seeder

### Community 40 - "DefaultRoleAssignment"
Cohesion: 0.31
Nodes (3): DefaultRoleAssignment, Illuminate\Support\Facades\Route, DefaultAssignmentPrecompilationTest

### Community 49 - "ReleaseEventFactory"
Cohesion: 0.38
Nodes (3): static, ReleaseEventFactory, ReleaseEventAction

### Community 56 - "require-dev"
Cohesion: 0.22
Nodes (9): require-dev, fakerphp/faker, larastan/larastan, laravel/pail, laravel/pao, laravel/pint, mockery/mockery, nunomaduro/collision (+1 more)

### Community 59 - "OrderedByPosition.php"
Cohesion: 0.54
Nodes (7): deleteAndResequence(), moveDown(), moveUp(), nextPosition(), resequence(), sequence(), swapWith()

### Community 61 - "require"
Cohesion: 0.25
Nodes (8): require, andreapollastri/larapilot, laravel/fortify, laravel/framework, laravel/tinker, livewire/flux, livewire/livewire, php

### Community 62 - "setup"
Cohesion: 0.25
Nodes (8): post-root-package-install, setup, composer install, npm install --ignore-scripts, npm run build, @php artisan key:generate, @php artisan migrate --force, @php -r \"file_exists('.env') || copy('.env.example', '.env');\

### Community 66 - "config"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 67 - "⚡fields.blade.php"
Cohesion: 0.29
Nodes (6): closeForm, delete(, moveDown(, moveUp(, openCreateForm, openEditForm(

### Community 68 - "⚡steps.blade.php"
Cohesion: 0.29
Nodes (6): closeForm, delete(, moveDown(, moveUp(, openCreateForm, openEditForm(

### Community 74 - "roles/⚡index.blade.php"
Cohesion: 0.33
Nodes (5): closeForm, delete(, openCreateForm, openEditForm(, toggleActivation(

### Community 75 - "templates/⚡index.blade.php"
Cohesion: 0.33
Nodes (5): closeForm, openCreateForm, openEditForm(, toggleActivation(, setAsDefault(

### Community 78 - "psr-4"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 79 - "logging.php"
Cohesion: 0.40
Nodes (4): Monolog\Handler\NullHandler, Monolog\Handler\StreamHandler, Monolog\Handler\SyslogUdpHandler, Monolog\Processor\PsrLogMessageProcessor

### Community 80 - "members/⚡index.blade.php"
Cohesion: 0.40
Nodes (4): closeForm, openCreateForm, openEditForm(, toggleActivation(

### Community 81 - "projects/⚡index.blade.php"
Cohesion: 0.40
Nodes (4): closeForm, openCreateForm, openEditForm(, toggleActivation(

### Community 82 - "larapilot-refresh"
Cohesion: 0.50
Nodes (4): larapilot-refresh, @composer dump-autoload, @php artisan boost:update --no-discover, @php artisan optimize:clear

### Community 83 - "post-create-project-cmd"
Cohesion: 0.50
Nodes (4): post-create-project-cmd, @php artisan key:generate --ansi, @php artisan migrate --graceful --ansi, @php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\

### Community 84 - "extra"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

## Knowledge Gaps
- **104 isolated node(s):** `Controller`, `private`, `$schema`, `build`, `dev` (+99 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **48 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `User` connect `User` to `TestCase`, `WorkflowTemplate`, `Illuminate\Database\Eloquent\Factories\Factory`, `FortifyServiceProvider.php`, `ReleaseStep`, `WellFormedLinkTest`, `ReleaseEvent`, `ReleaseStatus.php`, `Project`, `StartReleaseTest.php`, `Illuminate\Database\Eloquent\Concerns\HasUuids`, `Role`, `CloseStepTest`, `ProjectRoleAssignment`, `Release`, `ReleaseIndexScreenTest`, `ReleaseStepPolicyTest`, `Illuminate\Database\Eloquent\Relations\HasMany`, `MyStepsScreenTest`, `StartReleaseScreenTest`, `Illuminate\Database\Eloquent\Relations\BelongsTo`, `CloseStepScreenTest`, `ReleaseDetailScreenTest`, `StartReleaseTest`, `Illuminate\Database\Eloquent\Builder`, `ReleaseStepField`, `StepDefinitionManagementTest`, `ConfigurationPolicyTest`, `ReleaseLogScreenTest`, `DatabaseSeeder.php`, `FieldDefinitionManagementTest`, `RoleManagementTest`, `.projectReadyToRelease`, `DefaultRoleAssignment`, `SaveStepValuesTest`, `ReleasePolicyTest`, `ProjectPolicy`, `ReleaseEventFactory`, `LoginTest`, `SchemaConstraintsTest`, `MyStepsQueryBudgetTest`, `ReleaseDetailQueryBudgetTest`, `RolePolicy`, `UserPolicy`, `ReleaseIndexQueryBudgetTest`, `ReleaseStepPolicy`, `UserPolicyTest`, `ReleasePolicy`, `ReleaseEventPolicyTest`, `.projectWith`, `ReleaseEventPolicy`, `CloseStepQueryBudgetTest`, `ReleaseLogQueryBudgetTest`?**
  _High betweenness centrality (0.284) - this node is a cross-community bridge._
- **Why does `TestCase` connect `TestCase` to `WorkflowTemplate`, `FortifyServiceProvider.php`, `User`, `ReleaseStep`, `WellFormedLinkTest`, `ReleaseEvent`, `ReleaseStatus.php`, `Project`, `StartReleaseTest.php`, `Role`, `CloseStepTest`, `ProjectRoleAssignment`, `Release`, `FieldType`, `ReleaseIndexScreenTest`, `ReleaseStepPolicyTest`, `Illuminate\Database\Eloquent\Relations\HasMany`, `MyStepsScreenTest`, `StartReleaseScreenTest`, `CloseStepScreenTest`, `ReleaseDetailScreenTest`, `StartReleaseTest`, `StepDefinition`, `ReleaseStepField`, `StepDefinitionManagementTest`, `ConfigurationPolicyTest`, `ReleaseLogScreenTest`, `FieldDefinitionManagementTest`, `RoleManagementTest`, `.projectReadyToRelease`, `DefaultRoleAssignment`, `ReleaseSchemaConstraintsTest`, `FieldDefinition`, `SaveStepValuesTest`, `ReleasePolicyTest`, `LoginTest`, `SchemaConstraintsTest`, `MyStepsQueryBudgetTest`, `ReleaseDetailQueryBudgetTest`, `ReleaseIndexQueryBudgetTest`, `UserPolicyTest`, `ReleaseEventPolicyTest`, `.projectWith`, `CloseStepQueryBudgetTest`, `ReleaseLogQueryBudgetTest`?**
  _High betweenness centrality (0.093) - this node is a cross-community bridge._
- **Why does `ReleaseStep` connect `ReleaseStep` to `TestCase`, `Illuminate\Database\Eloquent\Factories\Factory`, `ReleaseEvent`, `ReleaseStatus.php`, `StartReleaseTest.php`, `Illuminate\Database\Eloquent\Concerns\HasUuids`, `CloseStepTest`, `Release`, `ReleaseIndexScreenTest`, `ReleaseStepPolicyTest`, `Illuminate\Database\Eloquent\Relations\HasMany`, `MyStepsScreenTest`, `StartReleaseScreenTest`, `Illuminate\Database\Eloquent\Relations\BelongsTo`, `CloseStepScreenTest`, `ReleaseDetailScreenTest`, `StartReleaseTest`, `Illuminate\Database\Eloquent\Builder`, `ReleaseStepField`, `DatabaseSeeder.php`, `.projectReadyToRelease`, `ReleaseSchemaConstraintsTest`, `SaveStepValuesTest`, `ReleaseEventFactory`, `MyStepsQueryBudgetTest`, `ReleaseDetailQueryBudgetTest`, `ReleaseIndexQueryBudgetTest`, `ReleaseStepPolicy`, `ReleaseEventPolicyTest`, `CloseStepQueryBudgetTest`, `ReleaseLogQueryBudgetTest`?**
  _High betweenness centrality (0.080) - this node is a cross-community bridge._
- **What connects `Controller`, `private`, `$schema` to the rest of the system?**
  _104 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `TestCase` be split into smaller, more focused modules?**
  _Cohesion score 0.05133161512027491 - nodes in this community are weakly interconnected._
- **Should `WorkflowTemplate` be split into smaller, more focused modules?**
  _Cohesion score 0.056265984654731455 - nodes in this community are weakly interconnected._
- **Should `Illuminate\Database\Eloquent\Factories\Factory` be split into smaller, more focused modules?**
  _Cohesion score 0.055152394775036286 - nodes in this community are weakly interconnected._