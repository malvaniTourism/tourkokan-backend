import React, { useState, useEffect } from 'react';
import { Container, Row, Col, Spinner } from 'react-bootstrap';
import { FaCheckCircle } from 'react-icons/fa';
import './styles.css';

const Pricing = () => {
    const [packages, setPackages] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const appUrl = process.env.MIX_APP_URL;

    useEffect(() => {
        fetch(`${appUrl}/api/v2/advertisingPackages`)
            .then((res) => res.json())
            .then((json) => {
                if (json.success) setPackages(json.data);
                else setError('Failed to load packages.');
            })
            .catch(() => setError('Failed to load packages.'))
            .finally(() => setLoading(false));
    }, []);

    return (
        <div className="pricing-section">
            <Container>
                <h2 className="text-center mb-2">Advertise with TourKokan</h2>
                <p className="text-center text-muted mb-5">
                    Reach thousands of Konkan travelers. Choose a plan that suits your business.
                </p>

                {loading && (
                    <div className="text-center py-5">
                        <Spinner animation="border" variant="primary" />
                    </div>
                )}

                {error && (
                    <p className="text-center text-danger">{error}</p>
                )}

                {!loading && !error && packages.length === 0 && (
                    <p className="text-center text-muted">No packages available at the moment.</p>
                )}

                <Row className="g-4 justify-content-center">
                    {packages.map((pkg, index) => {
                        const isPopular = index === Math.floor(packages.length / 2);
                        return (
                            <Col key={pkg.id} xs={12} sm={6} lg={4}>
                                <div className={`pricing-card ${isPopular ? 'pricing-card--popular' : ''}`}>
                                    {isPopular && (
                                        <div className="pricing-badge">Most Popular</div>
                                    )}
                                    <h4 className="pricing-name">{pkg.name}</h4>
                                    <div className="pricing-price">
                                        <span className="pricing-currency">₹</span>
                                        <span className="pricing-amount">{Number(pkg.price).toLocaleString('en-IN')}</span>
                                    </div>
                                    <p className="pricing-duration">for {pkg.duration_days} days</p>

                                    <ul className="pricing-features">
                                        <li>
                                            <FaCheckCircle className="pricing-check" />
                                            {pkg.duration_days}-day banner campaign
                                        </li>
                                        <li>
                                            <FaCheckCircle className="pricing-check" />
                                            Impressions &amp; click tracking
                                        </li>
                                        {pkg.allowed_placements && pkg.allowed_placements.map((placement, i) => (
                                            <li key={i}>
                                                <FaCheckCircle className="pricing-check" />
                                                {placement.replace(/_/g, ' ')} placement
                                            </li>
                                        ))}
                                    </ul>

                                    <a
                                        href="/#Contact"
                                        className={`pricing-btn ${isPopular ? 'pricing-btn--popular' : ''}`}
                                    >
                                        Get Started
                                    </a>
                                </div>
                            </Col>
                        );
                    })}
                </Row>
            </Container>
        </div>
    );
};

export default Pricing;
