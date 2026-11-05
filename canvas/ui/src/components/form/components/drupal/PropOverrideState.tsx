import { ExclamationTriangleIcon } from '@radix-ui/react-icons';
import { Box, Button, Flex, Text } from '@radix-ui/themes';

import PropOverrideLock, {
  usePropOverride,
} from '@/components/form/components/drupal/PropOverrideLock';
import FormElementLabel from '@/components/form/components/FormElementLabel';

import type { PropOverrideSeed } from '@/components/form/components/drupal/PropOverrideLock';

import styles from './PropOverrideState.module.css';

// What an inherited prop shows, as the server describes it.
export type PropOverrideDisplay =
  | { type: 'text'; text: string }
  | { type: 'image'; src: string; alt?: string }
  | { type: 'set' }
  | { type: 'unset' }
  // Where the form cannot tell what the placement shows, it says nothing.
  | { type: 'unknown' };

export interface PropOverrideStateProps {
  variant: 'inherited' | 'stale';
  // The exposed name of the prop.
  propName: string;
  title?: string;
  // Where the value comes from when it is not overridden.
  source?: 'pattern' | 'content';
  // Why an override that does not apply does not.
  reason?: string;
  display?: PropOverrideDisplay;
  seed?: PropOverrideSeed | '' | null;
}

const Value = ({ display }: { display?: PropOverrideDisplay }) => {
  switch (display?.type) {
    case 'text':
      return (
        <Text as="p" size="2" className={styles.value}>
          {display.text}
        </Text>
      );
    case 'image':
      return (
        <img
          className={styles.thumb}
          src={display.src}
          alt={display.alt ?? ''}
        />
      );
    case 'unknown':
      return null;
    case 'set':
      return (
        <Text as="p" size="2">
          Set
        </Text>
      );
    default:
      return (
        <Text as="p" size="2" color="gray">
          Not set
        </Text>
      );
  }
};

/**
 * A prop a Pattern exposes, in the form of one of its placements, when it is
 * not overridden: its label with a closed lock, and the value the placement
 * shows now as plain text. Clicking the lock overrides it, from that value. An
 * overridden prop is its normal field, with an open lock in its label (see
 * `DrupalFormElementLabel`).
 *
 * It is not a form field, so that editing one prop never turns another into an
 * override. An override that does not apply is the other state: a warning with
 * the reason and the way to remove it.
 */
const PropOverrideState = ({
  variant,
  propName,
  title = '',
  source = 'pattern',
  reason = '',
  display,
  seed,
}: PropOverrideStateProps) => {
  const { reset } = usePropOverride(propName);
  // An attribute holding a number or a boolean is parsed as one.
  const label = String(title) || propName;

  if (variant === 'stale') {
    return (
      <Box mb="4" data-testid={`prop-override-${propName}`}>
        <FormElementLabel>{label}</FormElementLabel>
        <div className={styles.stale}>
          <Flex gap="1" align="start">
            <ExclamationTriangleIcon
              color="var(--amber-10)"
              style={{ flexShrink: 0, marginTop: 2 }}
            />
            <Text size="1" color="amber">
              {reason}
            </Text>
          </Flex>
          <Button
            type="button"
            size="1"
            variant="soft"
            color="gray"
            onClick={reset}
          >
            Remove
          </Button>
        </div>
      </Box>
    );
  }

  return (
    <Box mb="4" data-testid={`prop-override-${propName}`}>
      <div className="canvas-linked-prop-label-wrapper">
        <FormElementLabel>{label}</FormElementLabel>
        <PropOverrideLock
          propName={propName}
          overridden={false}
          source={source}
          seed={seed || null}
        />
      </div>
      <Value display={display} />
    </Box>
  );
};

export default PropOverrideState;
