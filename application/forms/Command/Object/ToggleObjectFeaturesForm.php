<?php

/* Icinga DB Web | (c) 2021 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Icingadb\Forms\Command\Object;

use CallbackFilterIterator;
use Icinga\Module\Icingadb\Command\Object\ToggleObjectFeatureCommand;
use Icinga\Module\Icingadb\Forms\Command\CommandForm;
use Icinga\Web\Notification;
use ipl\Html\FormElement\CheckboxElement;
use ipl\Orm\Model;
use ipl\Web\FormDecorator\IcingaFormDecorator;
use Iterator;
use LimitIterator;
use NoRewindIterator;
use Traversable;

class ToggleObjectFeaturesForm extends CommandForm
{
    const LEAVE_UNCHANGED = 'noop';

    protected $features;

    protected $featureStatus;

    /**
     * ToggleFeature(s) being used to submit this form
     *
     * @var array<string, bool>
     */
    protected $submittedFeatures = [];

    public function __construct($featureStatus)
    {
        $this->featureStatus = $featureStatus;
        $this->features = [
            ToggleObjectFeatureCommand::FEATURE_ACTIVE_CHECKS => [
                'label'         => t('Active Checks'),
                'permission'    => 'icingadb/command/feature/object/active-checks'
            ],
            ToggleObjectFeatureCommand::FEATURE_PASSIVE_CHECKS => [
                'label'         => t('Passive Checks'),
                'permission'    => 'icingadb/command/feature/object/passive-checks'
            ],
            ToggleObjectFeatureCommand::FEATURE_NOTIFICATIONS => [
                'label'         => t('Notifications'),
                'permission'    => 'icingadb/command/feature/object/notifications'
            ],
            ToggleObjectFeatureCommand::FEATURE_EVENT_HANDLER => [
                'label'         => t('Event Handler'),
                'permission'    => 'icingadb/command/feature/object/event-handler'
            ],
            ToggleObjectFeatureCommand::FEATURE_FLAP_DETECTION => [
                'label'         => t('Flap Detection'),
                'permission'    => 'icingadb/command/feature/object/flap-detection'
            ]
        ];

        $this->getAttributes()->add('class', 'object-features');

        $this->on(self::ON_SUCCESS, function () {
            if ($this->errorOccurred) {
                return;
            }

            foreach ($this->submittedFeatures as $feature => $enabled) {
                switch ($feature) {
                    case ToggleObjectFeatureCommand::FEATURE_ACTIVE_CHECKS:
                        if ($enabled) {
                            $message = t('Enabled active checks successfully');
                        } else {
                            $message = t('Disabled active checks successfully');
                        }

                        break;
                    case ToggleObjectFeatureCommand::FEATURE_PASSIVE_CHECKS:
                        if ($enabled) {
                            $message = t('Enabled passive checks successfully');
                        } else {
                            $message = t('Disabled passive checks successfully');
                        }

                        break;
                    case ToggleObjectFeatureCommand::FEATURE_EVENT_HANDLER:
                        if ($enabled) {
                            $message = t('Enabled event handler successfully');
                        } else {
                            $message = t('Disabled event handler checks successfully');
                        }

                        break;
                    case ToggleObjectFeatureCommand::FEATURE_FLAP_DETECTION:
                        if ($enabled) {
                            $message = t('Enabled flap detection successfully');
                        } else {
                            $message = t('Disabled flap detection successfully');
                        }

                        break;
                    case ToggleObjectFeatureCommand::FEATURE_NOTIFICATIONS:
                        if ($enabled) {
                            $message = t('Enabled notifications successfully');
                        } else {
                            $message = t('Disabled notifications successfully');
                        }

                        break;
                    default:
                        $message = t('Invalid feature option');
                        break;
                }

                Notification::success($message);
            }
        });
    }

    protected function assembleElements()
    {
        $decorator = new IcingaFormDecorator();
        foreach ($this->features as $feature => $spec) {
            $options = [
                'class'         => 'autosubmit',
                'disabled'      => $this->featureStatus instanceof Model
                    ? ! $this->isGrantedOn($spec['permission'], $this->featureStatus)
                    : false,
                'label'         => $spec['label']
            ];
            if ($this->featureStatus[$feature] === 2) {
                $this->addElement(
                    'select',
                    $feature,
                    $options + [
                        'description'   => t('Multiple Values'),
                        'options'       => [
                            self::LEAVE_UNCHANGED => t('Leave Unchanged'),
                            t('Disable All'),
                            t('Enable All')
                        ],
                        'value'         => self::LEAVE_UNCHANGED
                    ]
                );
                $decorator->decorate($this->getElement($feature));

                $this->getElement($feature)
                    ->getWrapper()
                    ->getAttributes()
                    ->add('class', 'indeterminate');
            } else {
                $options['value'] = (bool) $this->featureStatus[$feature];
                $this->addElement('checkbox', $feature, $options);
                $decorator->decorate($this->getElement($feature));
            }
        }
    }

    protected function assembleSubmitButton()
    {
    }

    protected function getCommands(Iterator $objects): Traversable
    {
        foreach ($this->features as $feature => $spec) {
            if ($this->getElement($feature) instanceof CheckboxElement) {
                $state = $this->getElement($feature)->isChecked();
            } else {
                $state = $this->getElement($feature)->getValue();
            }

            if ($state === self::LEAVE_UNCHANGED || (int) $state === (int) $this->featureStatus[$feature]) {
                continue;
            }

            $granted = new CallbackFilterIterator($objects, function (Model $object) use ($spec): bool {
                return $this->isGrantedOn($spec['permission'], $object);
            });

            $command = new ToggleObjectFeatureCommand();
            $command->setFeature($feature);
            $command->setEnabled((int) $state);

            $granted->rewind(); // Forwards the pointer to the first element
            while ($granted->valid()) {
                $this->submittedFeatures[$command->getFeature()] ??= $command->getEnabled();

                // Chunk objects to avoid timeouts with large sets
                yield $command->setObjects(new LimitIterator(new NoRewindIterator($granted), 0, 1000));
            }
        }
    }
}
