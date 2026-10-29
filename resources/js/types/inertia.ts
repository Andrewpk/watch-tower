import type { User } from './auth';

export type ProjectSummary = {
    id: string;
    slug: string;
    name: string;
};

export type CurrentProject = ProjectSummary & {
    environment: string;
    open_issues_count: number;
};

export type MaintenanceProblem =
    | {
          key: 'prune_overdue';
          last_run_at: string | null;
          threshold_hours: number;
      }
    | {
          key: 'queue_stalled';
          waiting_since: string;
          threshold_minutes: number;
      };

export type LastPrune = {
    finished_at: string;
    rows_deleted: number | null;
};

export type SharedProps = {
    name: string;
    auth: { user: User | null };
    currentProject: CurrentProject | null;
    projects: ProjectSummary[];
    maintenanceProblems: MaintenanceProblem[];
};

export type NewApiKey = {
    project: string;
    key: string;
};
