<?php
/**
 * Tourfecto - Revenue Forecast Service
 * @version 1.0.0
 *
 * Section 2: REVENUE FORECASTING
 *
 * أسلوب متعمّد بسيط وشفاف (Simple Linear Regression على سلسلة إيراد
 * يومية حقيقية) بدل "AI بلاك بوكس" لا يمكن تفسيره - كل رقم في المخرجات
 * قابل لإعادة الاشتقاق من evidence. النتيجة دائمًا موصوفة كـ Estimated/
 * Forecast مع Confidence، وليست حقيقة (كما ينص section 2 صراحةً).
 *
 * لو البيانات غير كافية لموثوقية معقولة، نرجّع رسالة واضحة:
 * "Not enough data for reliable forecast." - لا نخترع أرقام.
 */
class RevenueForecastService {
    /** أقل عدد أيام فيها إيراد فعلي مسجّل عشان نحاول Forecast أصلاً. */
    public const MIN_DATA_POINTS = 10;

    /** @var RevenueDataGateway */
    private $gateway;

    public function __construct(?RevenueDataGateway $gateway = null) {
        $this->gateway = $gateway ?? new RevenueDataGateway();
    }

    /**
     * يولّد توقع إيراد للفترة القادمة (نفس طول period_type) بناءً على
     * آخر 90 يوم من بيانات فعلية.
     */
    public function forecast(int $userId, string $periodType = 'monthly', bool $persist = true): array {
        $lookbackDays = 90;
        $now = new DateTime('now');
        $from = (clone $now)->modify("-{$lookbackDays} days");
        $series = $this->gateway->getDailyRevenueSeries($userId, $from->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s'));

        $result = self::computeForecast($series, $periodType, $now->format('Y-m-d'));

        if ($persist) {
            try {
                $forecast = new RevaiForecast([
                    'user_id' => $userId,
                    'period_type' => $periodType,
                    'period_start' => $result['period']['from'],
                    'period_end' => $result['period']['to'],
                    'expected_revenue' => $result['expected_revenue'],
                    'low_estimate' => $result['forecast_range']['low'] ?? null,
                    'high_estimate' => $result['forecast_range']['high'] ?? null,
                    'confidence' => $result['confidence'],
                    'growth_trend' => $result['growth_trend'],
                    'method' => 'linear_regression_daily',
                    'data_points_used' => $result['data_points_used'],
                    'insufficient_data' => $result['insufficient_data'] ? 1 : 0,
                ]);
                $forecast->save();
            } catch (Exception $e) {
                if (class_exists('Logger')) {
                    Logger::error('RevenueForecastService: failed to persist forecast', ['message' => $e->getMessage()]);
                }
            }
        }

        // نضيف السلسلة اليومية التاريخية اللي اتحسب منها التوقع - عشان
        // الواجهة تقدر ترسم Chart واحد يوضح "التاريخي + المتوقع" مع بعض،
        // بدل ما تحتاج تعمل طلب تاني منفصل لنفس البيانات.
        $result['historical_series'] = array_map(static function ($p) {
            return ['date' => $p['date'], 'revenue' => $p['revenue']];
        }, $series);

        return $result;
    }

    /**
     * منطق الحساب الصِرف (Pure function) - بدون أي وصول لقاعدة بيانات،
     * عشان يكون قابل للاختبار مباشرة بـ Fixtures ثابتة (section 22).
     *
     * @param array $dailySeries [['date' => 'Y-m-d', 'revenue' => float], ...] مرتبة تصاعديًا
     * @param string $periodType daily/weekly/monthly/quarterly/yearly
     * @param string $todayStr Y-m-d - نقطة الانطلاق لحساب فترة التوقع القادمة
     */
    public static function computeForecast(array $dailySeries, string $periodType, string $todayStr): array {
        $futureDays = RevenueOverviewService::periodToDays($periodType);
        $today = new DateTime($todayStr);
        $periodFrom = (clone $today)->modify('+1 day');
        $periodTo = (clone $today)->modify('+' . $futureDays . ' days');

        $points = array_values(array_filter($dailySeries, static function ($p) { return isset($p['revenue']); }));
        $n = count($points);

        $base = [
            'period_type' => $periodType,
            'period' => ['from' => $periodFrom->format('Y-m-d'), 'to' => $periodTo->format('Y-m-d')],
            'data_points_used' => $n,
            'method' => 'linear_regression_daily',
        ];

        if ($n < self::MIN_DATA_POINTS) {
            return $base + [
                'insufficient_data' => true,
                'message' => 'Not enough data for reliable forecast.',
                'expected_revenue' => null,
                'forecast_range' => ['low' => null, 'high' => null],
                'confidence' => null,
                'growth_trend' => null,
            ];
        }

        // انحدار خطي بسيط (least squares) على y=revenue اليومي مقابل x=index اليوم.
        $xs = range(0, $n - 1);
        $ys = array_map(static function ($p) { return (float) $p['revenue']; }, $points);

        $meanX = array_sum($xs) / $n;
        $meanY = array_sum($ys) / $n;

        $num = 0.0; $den = 0.0;
        foreach ($xs as $i => $x) {
            $num += ($x - $meanX) * ($ys[$i] - $meanY);
            $den += ($x - $meanX) ** 2;
        }
        $slope = $den > 0 ? $num / $den : 0.0;
        $intercept = $meanY - $slope * $meanX;

        // معامل التحديد R^2 لقياس مدى ثبات/انتظام الاتجاه (يستخدم لتحديد Confidence)
        $ssTot = 0.0; $ssRes = 0.0;
        foreach ($xs as $i => $x) {
            $predicted = $intercept + $slope * $x;
            $ssRes += ($ys[$i] - $predicted) ** 2;
            $ssTot += ($ys[$i] - $meanY) ** 2;
        }
        $rSquared = $ssTot > 0 ? max(0.0, 1 - ($ssRes / $ssTot)) : 0.0;

        // توقع مجموع الإيراد لأيام الفترة القادمة عن طريق جمع القيم المتوقعة يوم بيوم
        $expectedTotal = 0.0;
        for ($d = 0; $d < $futureDays; $d++) {
            $x = $n + $d;
            $expectedTotal += max(0.0, $intercept + $slope * $x);
        }

        // نطاق التوقع: نستخدم الانحراف المعياري للأخطاء (residuals) كمقياس عدم يقين،
        // ونوسّعه تناسبيًا مع طول فترة التوقع.
        $variance = $n > 1 ? $ssRes / ($n - 1) : 0.0;
        $stdError = sqrt(max(0.0, $variance));
        $uncertainty = $stdError * sqrt($futureDays) * 1.5; // هامش تحفظي

        $low = max(0.0, $expectedTotal - $uncertainty);
        $high = $expectedTotal + $uncertainty;

        // Confidence: تجمع بين عدد نقاط البيانات وانتظام الاتجاه (R^2).
        if ($n >= 45 && $rSquared >= 0.4) {
            $confidence = 'high';
        } elseif ($n >= 20 && $rSquared >= 0.15) {
            $confidence = 'medium';
        } else {
            $confidence = 'low';
        }

        $growthTrend = 'flat';
        if ($meanY > 0) {
            $relativeSlope = ($slope * $n) / max($meanY, 0.01);
            if ($relativeSlope > 0.1) {
                $growthTrend = 'up';
            } elseif ($relativeSlope < -0.1) {
                $growthTrend = 'down';
            }
        }

        return $base + [
            'insufficient_data' => false,
            'message' => null,
            'expected_revenue' => round($expectedTotal, 2),
            'forecast_range' => ['low' => round($low, 2), 'high' => round($high, 2)],
            'confidence' => $confidence,
            'growth_trend' => $growthTrend,
            'r_squared' => round($rSquared, 3),
            'note' => 'Estimated forecast derived from historical trend. Not a guaranteed outcome.',
        ];
    }

    /**
     * Forecast Accuracy (إضافة): يقارن كل توقع قديم (فترته خلصت فعليًا)
     * بالإيراد الحقيقي اللي حصل في نفس الفترة بالظبط - عشان يديك مصداقية
     * حقيقية قابلة للتحقق للـ AI ("توقعنا 5000، حصل فعليًا 4800، دقة 96%")
     * بدل ما يفضل التوقع رقم مجرد محدش راجعه بعدين.
     */
    public function getAccuracyHistory(int $userId, int $limit = 10): array {
        $pastForecasts = $this->gateway->getPastForecasts($userId, $limit);
        if (empty($pastForecasts)) {
            return ['has_data' => false, 'message' => 'Not enough data', 'history' => [], 'average_accuracy_percent' => null];
        }

        $history = [];
        $accuracySum = 0.0;
        $accuracyCount = 0;

        foreach ($pastForecasts as $f) {
            $actualTotals = $this->gateway->getRevenueTotals(
                $userId,
                $f['period_start'] . ' 00:00:00',
                (new DateTime($f['period_end']))->modify('+1 day')->format('Y-m-d 00:00:00')
            );
            $actual = (float) $actualTotals['total'];
            $expected = (float) $f['expected_revenue'];

            $accuracyPercent = null;
            if ($actual > 0) {
                $accuracyPercent = round(max(0, 100 - (abs($actual - $expected) / $actual) * 100), 1);
                $accuracySum += $accuracyPercent;
                $accuracyCount++;
            }

            $history[] = [
                'period_type' => $f['period_type'],
                'period_start' => $f['period_start'],
                'period_end' => $f['period_end'],
                'expected_revenue' => $expected,
                'forecast_range' => ['low' => (float) $f['low_estimate'], 'high' => (float) $f['high_estimate']],
                'actual_revenue' => $actualTotals['count'] > 0 ? round($actual, 2) : null,
                'accuracy_percent' => $accuracyPercent,
                'within_range' => $actualTotals['count'] > 0
                    ? ($actual >= (float) $f['low_estimate'] && $actual <= (float) $f['high_estimate'])
                    : null,
                'confidence_at_time' => $f['confidence'],
            ];
        }

        return [
            'has_data' => true,
            'history' => $history,
            'average_accuracy_percent' => $accuracyCount > 0 ? round($accuracySum / $accuracyCount, 1) : null,
        ];
    }
}
