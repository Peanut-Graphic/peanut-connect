export { default as api } from './client';
export { isWordPressAdmin, getVersion } from './client';
export {
  settingsApi,
  healthApi,
  updatesApi,
  dashboardApi,
  errorLogApi,
  securityApi,
  permissionsApi,
  trackingApi,
} from './endpoints';
export { marketingApi } from './marketing';
export type {
  Utm,
  Link,
  CampaignBuildInput,
  CampaignResult,
  JourneyStats,
  TrackingSetup,
  Paginated,
} from './marketing';
