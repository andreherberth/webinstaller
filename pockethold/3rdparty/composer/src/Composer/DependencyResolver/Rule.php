<?php











namespace Composer\DependencyResolver;

use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Package\AliasPackage;
use Composer\Repository\RepositorySet;
use Composer\Repository\PlatformRepository;
use Composer\Package\Version\VersionParser;
use Composer\Semver\Constraint\Constraint;





abstract class Rule
{

 const RULE_ROOT_REQUIRE = 2;
const RULE_FIXED = 3;
const RULE_PACKAGE_CONFLICT = 6;
const RULE_PACKAGE_REQUIRES = 7;
const RULE_PACKAGE_SAME_NAME = 10;
const RULE_LEARNED = 12;
const RULE_PACKAGE_ALIAS = 13;


 const BITFIELD_TYPE = 0;
const BITFIELD_REASON = 8;
const BITFIELD_DISABLED = 16;

protected $bitfield;
protected $request;
protected $reasonData;





public function __construct($reason, $reasonData)
{
$this->reasonData = $reasonData;

$this->bitfield = (0 << self::BITFIELD_DISABLED) |
($reason << self::BITFIELD_REASON) |
(255 << self::BITFIELD_TYPE);
}

abstract public function getLiterals();

abstract public function getHash();

abstract public function equals(Rule $rule);

public function getReason()
{
return ($this->bitfield & (255 << self::BITFIELD_REASON)) >> self::BITFIELD_REASON;
}

public function getReasonData()
{
return $this->reasonData;
}

public function getRequiredPackage()
{
$reason = $this->getReason();

if ($reason === self::RULE_ROOT_REQUIRE) {
return $this->reasonData['packageName'];
}

if ($reason === self::RULE_FIXED) {
return $this->reasonData['package']->getName();
}

if ($reason === self::RULE_PACKAGE_REQUIRES) {
return $this->reasonData->getTarget();
}
}

public function setType($type)
{
$this->bitfield = ($this->bitfield & ~(255 << self::BITFIELD_TYPE)) | ((255 & $type) << self::BITFIELD_TYPE);
}

public function getType()
{
return ($this->bitfield & (255 << self::BITFIELD_TYPE)) >> self::BITFIELD_TYPE;
}

public function disable()
{
$this->bitfield = ($this->bitfield & ~(255 << self::BITFIELD_DISABLED)) | (1 << self::BITFIELD_DISABLED);
}

public function enable()
{
$this->bitfield &= ~(255 << self::BITFIELD_DISABLED);
}

public function isDisabled()
{
return (bool) (($this->bitfield & (255 << self::BITFIELD_DISABLED)) >> self::BITFIELD_DISABLED);
}

public function isEnabled()
{
return !(($this->bitfield & (255 << self::BITFIELD_DISABLED)) >> self::BITFIELD_DISABLED);
}

abstract public function isAssertion();

public function isCausedByLock(RepositorySet $repositorySet, Request $request, Pool $pool)
{
if ($this->getReason() === self::RULE_FIXED && $this->reasonData['lockable']) {
return true;
}

if ($this->getReason() === self::RULE_PACKAGE_REQUIRES) {
if (preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $this->reasonData->getTarget())) {
return false;
}
foreach ($request->getFixedPackages() as $package) {
if ($package->getName() === $this->reasonData->getTarget()) {
if ($pool->isUnacceptableFixedPackage($package)) {
return true;
}
if (!$this->reasonData->getConstraint()->matches(new Constraint('=', $package->getVersion()))) {
return true;
}
break;
}
}
}

if ($this->getReason() === self::RULE_ROOT_REQUIRE) {
if (preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $this->reasonData['packageName'])) {
return false;
}
foreach ($request->getFixedPackages() as $package) {
if ($package->getName() === $this->reasonData['packageName']) {
if ($pool->isUnacceptableFixedPackage($package)) {
return true;
}
if (!$this->reasonData['constraint']->matches(new Constraint('=', $package->getVersion()))) {
return true;
}
break;
}
}
}

return false;
}

public function getPrettyString(RepositorySet $repositorySet, Request $request, Pool $pool, $isVerbose, array $installedMap = array(), array $learnedPool = array())
{
$literals = $this->getLiterals();

switch ($this->getReason()) {
case self::RULE_ROOT_REQUIRE:
$packageName = $this->reasonData['packageName'];
$constraint = $this->reasonData['constraint'];

$packages = $pool->whatProvides($packageName, $constraint);
if (!$packages) {
return 'No package found to satisfy root composer.json require '.$packageName.($constraint ? ' '.$constraint->getPrettyString() : '');
}

return 'Root composer.json requires '.$packageName.($constraint ? ' '.$constraint->getPrettyString() : '').' -> satisfiable by '.$this->formatPackagesUnique($pool, $packages, $isVerbose).'.';

case self::RULE_FIXED:
$package = $this->deduplicateDefaultBranchAlias($this->reasonData['package']);
if ($this->reasonData['lockable']) {
return $package->getPrettyName().' is locked to version '.$package->getPrettyVersion().' and an update of this package was not requested.';
}

return $package->getPrettyName().' is present at version '.$package->getPrettyVersion() . ' and cannot be modified by Composer';

case self::RULE_PACKAGE_CONFLICT:
$package1 = $this->deduplicateDefaultBranchAlias($pool->literalToPackage($literals[0]));
$package2 = $this->deduplicateDefaultBranchAlias($pool->literalToPackage($literals[1]));

return $package2->getPrettyString().' conflicts with '.$package1->getPrettyString().'.';

case self::RULE_PACKAGE_REQUIRES:
$sourceLiteral = array_shift($literals);
$sourcePackage = $this->deduplicateDefaultBranchAlias($pool->literalToPackage($sourceLiteral));

$requires = array();
foreach ($literals as $literal) {
$requires[] = $pool->literalToPackage($literal);
}

$text = $this->reasonData->getPrettyString($sourcePackage);
if ($requires) {
$text .= ' -> satisfiable by ' . $this->formatPackagesUnique($pool, $requires, $isVerbose) . '.';
} else {
$targetName = $this->reasonData->getTarget();

$reason = Problem::getMissingPackageReason($repositorySet, $request, $pool, $isVerbose, $targetName, $this->reasonData->getConstraint());

return $text . ' -> ' . $reason[1];
}

return $text;

case self::RULE_PACKAGE_SAME_NAME:
$packageNames = array();
foreach ($literals as $literal) {
$package = $pool->literalToPackage($literal);
$packageNames[$package->getName()] = true;
}
$replacedName = $this->reasonData;

if (count($packageNames) > 1) {
$reason = null;

if (!isset($packageNames[$replacedName])) {
$reason = 'They '.(count($literals) == 2 ? 'both' : 'all').' replace '.$replacedName.' and thus cannot coexist.';
} else {
$replacerNames = $packageNames;
unset($replacerNames[$replacedName]);
$replacerNames = array_keys($replacerNames);

if (count($replacerNames) == 1) {
$reason = $replacerNames[0] . ' replaces ';
} else {
$reason = '['.implode(', ', $replacerNames).'] replace ';
}
$reason .= $replacedName.' and thus cannot coexist with it.';
}

$installedPackages = array();
$removablePackages = array();
foreach ($literals as $literal) {
if (isset($installedMap[abs($literal)])) {
$installedPackages[] = $pool->literalToPackage($literal);
} else {
$removablePackages[] = $pool->literalToPackage($literal);
}
}

if ($installedPackages && $removablePackages) {
return $this->formatPackagesUnique($pool, $removablePackages, $isVerbose).' cannot be installed as that would require removing '.$this->formatPackagesUnique($pool, $installedPackages, $isVerbose).'. '.$reason;
}

return 'Only one of these can be installed: '.$this->formatPackagesUnique($pool, $literals, $isVerbose).'. '.$reason;
}

return 'You can only install one version of a package, so only one of these can be installed: ' . $this->formatPackagesUnique($pool, $literals, $isVerbose) . '.';
case self::RULE_LEARNED:
if (isset($learnedPool[$this->reasonData])) {
$learnedString = ', learned rules:' . Problem::formatDeduplicatedRules($learnedPool[$this->reasonData], '        ', $repositorySet, $request, $pool, $isVerbose, $installedMap, $learnedPool);
} else {
$learnedString = ' (reasoning unavailable)';
}

if (count($literals) === 1) {
$ruleText = $pool->literalToPrettyString($literals[0], $installedMap);
} else {
$groups = array();
foreach ($literals as $literal) {
$package = $pool->literalToPackage($literal);
if (isset($installedMap[$package->id])) {
$group = $literal > 0 ? 'keep' : 'remove';
} else {
$group = $literal > 0 ? 'install' : 'don\'t install';
}

$groups[$group][] = $this->deduplicateDefaultBranchAlias($package);
}
$ruleTexts = array();
foreach ($groups as $group => $packages) {
$ruleTexts[] = $group . (count($packages) > 1 ? ' one of' : '').' ' . $this->formatPackagesUnique($pool, $packages, $isVerbose);
}

$ruleText = implode(' | ', $ruleTexts);
}

return 'Conclusion: '.$ruleText.$learnedString;
case self::RULE_PACKAGE_ALIAS:
$aliasPackage = $pool->literalToPackage($literals[0]);

 if ($aliasPackage->getVersion() === VersionParser::DEFAULT_BRANCH_ALIAS) {
return '';
}
$package = $this->deduplicateDefaultBranchAlias($pool->literalToPackage($literals[1]));

return $aliasPackage->getPrettyString() .' is an alias of '.$package->getPrettyString().' and thus requires it to be installed too.';
default:
$ruleText = '';
foreach ($literals as $i => $literal) {
if ($i != 0) {
$ruleText .= '|';
}
$ruleText .= $pool->literalToPrettyString($literal, $installedMap);
}

return '('.$ruleText.')';
}
}







protected function formatPackagesUnique($pool, array $packages, $isVerbose)
{
$prepared = array();
foreach ($packages as $index => $package) {
if (!\is_object($package)) {
$packages[$index] = $pool->literalToPackage($package);
}
}

return Problem::getPackageList($packages, $isVerbose);
}

private function getReplacedNames(PackageInterface $package)
{
$names = array();
foreach ($package->getReplaces() as $link) {
$names[] = $link->getTarget();
}

return $names;
}

private function deduplicateDefaultBranchAlias(PackageInterface $package)
{
if ($package instanceof AliasPackage && $package->getPrettyVersion() === VersionParser::DEFAULT_BRANCH_ALIAS) {
$package = $package->getAliasOf();
}

return $package;
}
}