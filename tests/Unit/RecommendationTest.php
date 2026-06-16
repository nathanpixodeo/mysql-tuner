<?php

use MySQLTuner\Recommendation\Recommendation;
use MySQLTuner\Recommendation\Severity;
use PHPUnit\Framework\TestCase;

class RecommendationTest extends TestCase
{
    public function testCreateOkRecommendation(): void
    {
        $rec = Recommendation::ok('test_metric', 'Everything is fine', '42');
        $this->assertSame('test_metric', $rec->metric);
        $this->assertSame('Everything is fine', $rec->summary);
        $this->assertSame(Severity::OK, $rec->severity);
        $this->assertSame('42', $rec->currentValue);
    }

    public function testCreateWarningRecommendation(): void
    {
        $rec = Recommendation::warn('test_warn', 'Something needs attention', 'Increase value', '42');
        $this->assertSame(Severity::WARNING, $rec->severity);
        $this->assertSame('Increase value', $rec->suggestion);
    }

    public function testCreateCriticalRecommendation(): void
    {
        $rec = Recommendation::critical('test_crit', 'Critical issue', 'Fix immediately', '0');
        $this->assertSame(Severity::CRITICAL, $rec->severity);
    }

    public function testSeverityWeights(): void
    {
        $this->assertSame(0, Severity::OK->weight());
        $this->assertSame(1, Severity::INFO->weight());
        $this->assertSame(3, Severity::WARNING->weight());
        $this->assertSame(5, Severity::CRITICAL->weight());
    }

    public function testToArray(): void
    {
        $rec = new Recommendation('metric1', 'Summary text', Severity::WARNING, '42', 'Suggestion', 'Detail', 'group1');
        $arr = $rec->toArray();

        $this->assertSame('metric1', $arr['metric']);
        $this->assertSame('WARNING', $arr['severity']);
        $this->assertSame('Summary text', $arr['summary']);
        $this->assertSame('42', $arr['current_value']);
        $this->assertSame('Suggestion', $arr['suggestion']);
        $this->assertSame('Detail', $arr['detail']);
        $this->assertSame('group1', $arr['group']);
    }

    public function testRecommendationIsReadonly(): void
    {
        $rec = Recommendation::ok('test', 'OK');
        $this->assertTrue(property_exists($rec, 'metric'));
    }
}
