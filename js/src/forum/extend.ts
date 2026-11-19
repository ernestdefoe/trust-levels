import Extend from 'flarum/common/extenders';
import UserPageResolver from 'flarum/forum/resolvers/UserPageResolver';
import TrustLevel from '../common/TrustLevel';
import { LevelReachedNotification, LevelDroppedNotification } from './components/LevelNotification';

export default [
  new Extend.Store().add('trust-levels', TrustLevel),

  // The profile tab's page loads when someone opens it, not with every page.
  new Extend.Routes().add('user.trust', '/u/:username/trust', () => import('./components/TrustPage'), UserPageResolver),

  new Extend.Notification().add('trustLevelReached', LevelReachedNotification).add('trustLevelDropped', LevelDroppedNotification),
];
