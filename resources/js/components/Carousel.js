import React from 'react';
import Carousel from 'react-bootstrap/Carousel';
import './styles.css';

const carouselItems = [
    { src: "/assets/corousel/m4.jpg", alt: "Devgad Beach" },
    { src: "/assets/corousel/m3.jpg", alt: "Malvan Scuba Diving" },
    { src: "/assets/corousel/m6.jpg", alt: "Agnewadi Bharadi Devi" },
];

function MyCarousel() {
    return (
        <Carousel data-bs-theme="dark">
            {carouselItems.map((item, index) => (
                <Carousel.Item key={index} style={{ cursor: "pointer" }}>
                    <a href="https://forms.gle/mE6xg4XByUtZV5ae7" target="_blank" rel="noreferrer">
                        <img
                            className="d-block w-100 carousel-img"
                            src={item.src}
                            alt={item.alt}
                        />
                    </a>
                </Carousel.Item>
            ))}
        </Carousel>
    );
}

export default MyCarousel;
