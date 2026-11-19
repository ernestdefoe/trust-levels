import app from 'flarum/common/app';
import Model from 'flarum/common/Model';
import type { Progress } from '../forum/components/TrustPage';

/**
 * A member's trust level and progress, the `trust-levels` JSON:API type
 * (id = the member's user id). Only the member and staff can load it.
 */
export default class TrustLevel extends Model {
  progress(): Progress {
    return this.data.attributes as unknown as Progress;
  }
}

/** The member's progress, from the API (once per call: it moves as they read and post). */
export function loadProgress(userId: string, errorHandler?: (e: unknown) => void): Promise<Progress> {
  return app.store.find<TrustLevel>('trust-levels', userId, {}, errorHandler ? { errorHandler } : {}).then((model) => model.progress());
}

/** Staff: change a member's level, lock, note or hold; answers with the progress after it. */
export function saveProgress(userId: string, attributes: Partial<Pick<Progress, 'level' | 'locked' | 'note' | 'silenced'>>): Promise<Progress> {
  const model =
    app.store.getById<TrustLevel>('trust-levels', userId) || app.store.createRecord<TrustLevel>('trust-levels', { type: 'trust-levels', id: userId });
  model.exists = true;

  return model.save(attributes).then((saved) => saved.progress());
}
